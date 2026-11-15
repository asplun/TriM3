<?php
/* TriM3 · 图片处理共用管线
 *
 * 只写一份「缓存键 + 缩放/裁剪 + 编码」规则，供两个入口共用：
 *   api/img.php        实时出图（浏览器请求）
 *   api/img-cache.php  预生成（后台/计划任务批量把缓存做好）
 * 两者键与字节必须完全一致，否则预生成出来的文件前台命不中，等于白做。
 *
 * 本文件不依赖 Typecho，可被不引导框架的入口直接 include。
 */

/* 前台真正会向代理索取的宽度。
 * 取值依据：各模板 srcset 实测候选 + tri3_img_proxy_url() 的基准宽度：
 *   index.php 封面 640/960/1240、page-albums.php 列表 240/360/480/640、
 *   相册内页 320/480/640/960、api/list-api.php 内联相册 360/540/720/900、
 *   正文图与卡片基准 800、老模板遗留 128/320/400/600/1200。
 * 预生成必须与前台候选一致，否则预热出来的文件前台永远命不中。 */
function tri3_img_pipeline_widths()
{
    return array(240, 320, 360, 400, 480, 540, 600, 640, 720, 800, 900, 960, 1240);
}

/* 磁盘缓存文件名 —— 与 api/img.php 的键规则一字不差 */
function tri3_img_cache_file($cacheDir, $src, $w, $crop, $fmt, $q)
{
    $key = md5($src . '|' . (int) $w . '|' . ($crop ? 'c' : 'n') . '|' . $fmt . '|' . (int) $q);
    return rtrim($cacheDir, '/') . '/' . $key . '.' . ($fmt === 'webp' ? 'webp' : 'jpg');
}

/* 等比缩放（可选中心裁剪成正方形）+ 编码，返回字节串；失败返回空串 */
function tri3_img_transform($data, $w, $crop, $q, $fmt)
{
    $im = @imagecreatefromstring($data);
    if (!$im) { return ''; }
    $w = (int) $w;
    $ow = imagesx($im);
    $oh = imagesy($im);

    if ($crop) {
        /* 裁剪成以 w 为边长的正方形（居中裁剪） */
        $side = min($ow, $oh, $w);
        $sx = (int) round(($ow - $side) / 2);
        $sy = (int) round(($oh - $side) / 2);
        $dst = imagecreatetruecolor($side, $side);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagecopy($dst, $im, 0, 0, $sx, $sy, $side, $side);
        if ($w > $side) {
            $tmp = imagecreatetruecolor($w, $w);
            imagealphablending($tmp, false);
            imagesavealpha($tmp, true);
            imagefill($tmp, 0, 0, $transparent);
            imagecopyresampled($tmp, $dst, 0, 0, 0, 0, $w, $w, $side, $side);
            $dst = $tmp;
        }
        $im = $dst;
    } elseif ($ow > $w && $ow > 0 && $oh > 0) {
        $nh = (int) round($oh * $w / $ow);
        $tmp = imagecreatetruecolor($w, $nh);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
        imagefill($tmp, 0, 0, $transparent);
        imagecopyresampled($tmp, $im, 0, 0, 0, 0, $w, $nh, $ow, $oh);
        $im = $tmp;
    }

    ob_start();
    if ($fmt === 'webp' && function_exists('imagewebp')) {
        imagewebp($im, null, (int) $q);
    } else {
        imagejpeg($im, null, (int) $q);
    }
    return (string) ob_get_clean();
}

/* 单张原图字节 → 一次性产出多档缓存（预生成专用：原图只下载一次、只解码一次）
 * 返回 array(generated => int, skipped => int, failed => int) */
function tri3_img_warm_variants($data, $src, $cacheDir, $widths, $fmt, $q)
{
    $r = array('generated' => 0, 'skipped' => 0, 'failed' => 0);
    foreach ($widths as $w) {
        $file = tri3_img_cache_file($cacheDir, $src, $w, false, $fmt, $q);
        if (is_file($file) && @filesize($file) > 0) { $r['skipped']++; continue; }
        $out = tri3_img_transform($data, $w, false, $q, $fmt);
        if ($out === '') { $r['failed']++; continue; }
        if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
        if (@file_put_contents($file, $out) !== false) { $r['generated']++; } else { $r['failed']++; }
    }
    return $r;
}

/* CDN（多吉云）Type A 签名：与 api/img.php、lib/helpers.php 同一算法 */
function dogyun_sign_url($url, $key, $uid, $expire)
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['path'])) { return $url; }
    if (!empty($parts['query']) && strpos($parts['query'], 'auth_key=') !== false) { return $url; }
    $ts   = time() + (int) $expire;
    $rand = substr(bin2hex(random_bytes(16)), 0, 24);
    $raw  = $parts['path'] . '-' . $ts . '-' . $rand . '-' . $uid . '-' . $key;
    $authKey = $ts . '-' . $rand . '-' . $uid . '-' . md5($raw);
    $sep = empty($parts['query']) ? '?' : '&';
    return $url . $sep . 'auth_key=' . $authKey;
}

/* 取原图字节（带 CDN 鉴权与 Referer）——预生成与实时出图共用同一套取值方式 */
function tri3_img_fetch_origin($src, $cacheDir)
{
    /* 站点配置（域名白名单 / CDN 密钥）由主题写在 assets/cache/img-hosts.php */
    $conf = array();
    $confFile = dirname(__DIR__) . '/assets/cache/img-hosts.php';   /* lib/ 的上一级即主题根 */
    if (is_file($confFile)) {
        $d = @include $confFile;
        if (is_array($d)) { $conf = isset($d['extra']) && is_array($d['extra']) ? $d['extra'] : array(); }
    }
    $fetch = $src;
    $key = trim((string) ($conf['dogyunKey'] ?? ''));
    $domain = '';
    foreach (array((string) ($conf['qiniuDomain'] ?? ''), (string) ($conf['albumApiDomain'] ?? '')) as $u) {
        $u = trim($u);
        if ($u === '') { continue; }
        $h = @parse_url(strpos($u, '//') === false ? '//' . $u : $u, PHP_URL_HOST);
        if (is_string($h) && $h !== '') { $domain = strtolower($h); break; }
    }
    if ($key !== '' && $domain !== '' && strpos($src, $domain) !== false) {
        $fetch = dogyun_sign_url($src, $key, '0', 604800);
    }
    $referer = trim((string) ($conf['siteUrl'] ?? ''));
    if ($referer === '') { $referer = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'); }
    if (substr($referer, -1) !== '/') { $referer .= '/'; }
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 20,
        'header'  => "User-Agent: TriM3-Img/2.0\r\nAccept: image/webp,image/*,*/*;q=0.8\r\nReferer: " . $referer . "\r\n",
    )));
    $data = @file_get_contents($fetch, false, $ctx);
    return (is_string($data) && $data !== '') ? $data : '';
}
