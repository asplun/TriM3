<?php
/* TriM3 · 图片代理（缩放/裁剪 + WebP + 磁盘缓存）+ 多吉云 CDN 鉴权
 *
 * 用法：/usr/themes/TriM3/api/img.php?src=<urlencoded>&w=800&c=1&f=webp&q=80
 *  - src 仅允许白名单域名（配置的图床域名 / 本站域名），防 SSRF
 *  - w 目标宽度（默认读后台设置，范围 32~2000）
 *  - c 裁剪：1=中心点裁剪成正方形（头像/封面用）
 *  - f webp | jpeg | auto（auto 表示浏览器支持则 webp，否则 jpeg；可被后台关闭）
 *  - q 质量 30~95（默认读后台设置）
 *
 * 后台开关（theme:TriM3）：
 *  - imgProxyEnable  是否启用图片代理（0=直接输出原图，不经过 img.php）
 *  - imgWebpEnable   是否启用 WebP 输出（0=强制 JPEG）
 *  - imgDefaultW     默认宽度（默认 800）
 *  - imgDefaultQ     默认质量（默认 70）
 *  - imgCacheMax     缓存文件数量上限（超过自动清理旧文件，默认 800）
 */
/* 图片流不能被任何 PHP 输出污染（警告/错误会破坏解码）——必须在任何 include 之前 */
@require_once dirname(__DIR__) . '/lib/img-dims.php';
ini_set('display_errors', '0');
error_reporting(0);
@ini_set('memory_limit', '512M');

/* 站点相关配置（域名白名单 / CDN 域名与密钥）：由主题渲染页面时写入
 * assets/cache/img-hosts.php，这里只 include 读取。
 * 刻意不引导 Typecho —— 其 Response 会在 shutdown 阶段把响应的状态码强制改回
 * 200（实测确认），会让本文件的 403 失效；顺带也省掉每条图片请求的初始化开销。
 * 文件缺失时降级为公用白名单：未命中的域名一律 403，
 * 不让服务端抓取陌生地址，也不把本接口当开放重定向。 */
$triConfExtra = array();
$triConfHosts = array();
$triConfFile  = dirname(__DIR__) . '/assets/cache/img-hosts.php';
if (is_file($triConfFile)) {
    $triConfData = @include $triConfFile;
    if (is_array($triConfData)) {
        if (isset($triConfData['hosts']) && is_array($triConfData['hosts'])) { $triConfHosts = $triConfData['hosts']; }
        if (isset($triConfData['extra']) && is_array($triConfData['extra'])) { $triConfExtra = $triConfData['extra']; }
    }
}
if (!defined('__TYPECHO_ROOT_DIR__')) { define('__TYPECHO_ROOT_DIR__', dirname(__DIR__, 4)); }

/* ============ 读取后台设置 ============ */
function tri_img_get_opt($key, $default) {
    static $opts = null;
    if ($opts === null) {
        $opts = [];
        try {
            $db = \Typecho\Db::get();
            $raw = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'theme:TriM3'));
            if ($raw && !empty($raw['value'])) {
                $decoded = json_decode($raw['value'], true);
                if (is_array($decoded)) $opts = $decoded;
            }
        } catch (\Throwable $e) {}
    }
    return isset($opts[$key]) ? $opts[$key] : $default;
}

/* 读取主题写入的站点配置（assets/cache/img-hosts.php）；缺失时返回默认值 */
function tri_img_conf($key, $default = '')
{
    global $triConfExtra;
    return (isset($triConfExtra[$key]) && $triConfExtra[$key] !== '') ? $triConfExtra[$key] : $default;
}

/* 读取「后台开关」：优先用主题写进 img-hosts.php 的 extra（不引导 Typecho，
 * 快且稳定），只有缺失时才回退数据库查询。此前只查数据库 → 本文件从未
 * 引导 Typecho，查询必然抛错被吞，imgWebpEnable/imgDefaultW 等开关永远不生效。 */
function tri_img_opt($key, $default)
{
    $triV = tri_img_conf($key, '');
    if ($triV !== '') { return $triV; }
    return tri_img_get_opt($key, $default);
}

/* 原子写：先写同目录临时文件再 rename，避免读侧读到半截图（CDN 会把半截图缓存 30 天） */
function tri_img_cache_put($file, $data)
{
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $data) === false) { @unlink($tmp); return false; }
    if (!@rename($tmp, $file)) { @unlink($tmp); @file_put_contents($file, $data); }
    return true;
}

/* 代理总开关：关闭时仍先做域名白名单校验，避免变成开放重定向 */
$triProxyOn = (string) tri_img_opt('imgProxyEnable', '1') === '1';

/* ============ CDN URL 鉴权（多吉云 Type A 签名，可选） ============
 * 域名取自后台「图床设置」的七牛/自定义域名，密钥取自 dogyunKey；
 * 两者都配置才启用 —— 未配置的站点该分支不生效，不影响正常出图。 */
$dogyunDomain = '';
foreach (array((string) tri_img_conf('qiniuDomain', ''), (string) tri_img_conf('albumApiDomain', '')) as $triDgU) {
    $triDgU = trim($triDgU);
    if ($triDgU === '') { continue; }
    $triDgH = @parse_url((strpos($triDgU, '//') === false) ? '//' . $triDgU : $triDgU, PHP_URL_HOST);
    if (is_string($triDgH) && $triDgH !== '') { $dogyunDomain = strtolower($triDgH); break; }
}
$dogyunKey    = trim((string) tri_img_conf('dogyunKey', ''));
$dogyunUid    = '0';
$dogyunExpire = 604800; /* 7 天，与 helpers.php 保持一致 */


/* CDN 签名改到「下载原图」处执行：签名含随机串，若在此处改动 $src，
 * 磁盘缓存键每次都不同 → 缓存永远无法命中（旧版问题）。 */

require_once dirname(__DIR__) . '/lib/img-pipeline.php';

$src = isset($_GET['src']) ? trim((string) $_GET['src']) : '';
$defaultW = (int) tri_img_opt('imgDefaultW', 800);
$defaultQ = (int) tri_img_opt('imgDefaultQ', 80); /* 与后台面板默认值一致（此前 70） */
$webpOn   = (string) tri_img_opt('imgWebpEnable', '1') === '1';
$cacheMax = (int) tri_img_opt('imgCacheMax', 800);
if ($cacheMax < 100) $cacheMax = 100;

$w = isset($_GET['w']) ? (int) $_GET['w'] : $defaultW;
$w = max(32, min($w, 2000));
$crop = isset($_GET['c']) && $_GET['c'] === '1';
$fmt = isset($_GET['f']) ? strtolower(trim((string) $_GET['f'])) : 'auto';
$triFmtAuto = ($fmt === 'auto'); /* 同一 URL 按 Accept 返回不同格式 → 必须带 Vary */
$q = isset($_GET['q']) ? (int) $_GET['q'] : $defaultQ;
$q = max(30, min($q, 95));

/* WebP 开关：后台关闭则强制 JPEG */
if (!$webpOn && $fmt === 'auto') $fmt = 'jpeg';
if ($fmt === 'auto') {
    $fmt = (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false && function_exists('imagewebp'))
        ? 'webp' : 'jpeg';
}
if ($fmt !== 'webp') { $fmt = 'jpeg'; }

/* 域名白名单（防 SSRF）
 * 清单由主题依据 typecho_options.siteUrl（管理员设定，请求头无法伪造）
 * 连同其子域、后台图床域名生成 → 换域名安装后自动生效；
 * '.' 前缀条目匹配其任意子域（图床/静态 CDN 常走 img.example.com）。 */
$parts = @parse_url($src);
$host = isset($parts['host']) ? strtolower($parts['host']) : '';
$scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
$allowHosts = array_merge(array('img95.699pic.com', 'ts1.tc.mm.bing.net', 'ts2.tc.mm.bing.net', 'ts3.tc.mm.bing.net', 'ts4.tc.mm.bing.net'), $triConfHosts);
$allowHosts = array_values(array_unique(array_filter($allowHosts)));
$triHostOk = in_array($host, $allowHosts, true);
if (!$triHostOk) {
    foreach ($allowHosts as $triAOne) {
        if ($triAOne !== '' && $triAOne[0] === '.' && substr($host, -strlen($triAOne)) === $triAOne) { $triHostOk = true; break; }
    }
}
/* 未命中：一律 403，避免图片代理被当作开放重定向。 */
if ($src === '' || !in_array($scheme, array('http', 'https'), true) || $host === '' || !$triHostOk) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('forbidden');
}
if (!preg_match('/\.(jpe?g|png|webp|gif|bmp)(\?|$)/i', $src)) {
    http_response_code(403);
    exit('forbidden');
}

/* 代理关闭：只允许白名单域名 302 回原图，非白名单一律 403 */
if (!$triProxyOn) {
    if ($triHostOk) {
        header('Location: ' . $src);
        exit;
    }
    http_response_code(403);
    exit('forbidden');
}

/* 磁盘缓存 */
$cacheDir = dirname(__DIR__) . '/assets/cache/img/';
$cacheFile = tri3_img_cache_file($cacheDir, $src, $w, $crop, $fmt, $q);

if (is_file($cacheFile)) {
    $data = @file_get_contents($cacheFile);
    if ($data !== false && $data !== '') {
        if (strncmp($data, '302:', 4) === 0) {
            header('Location: ' . substr($data, 4));
            exit;
        }
        /* 魔数校验：只有完整 JPEG/WebP 才输出，半截图直接丢弃重建 */
        $triMagicOk = ($fmt === 'webp')
            ? (strncmp($data, 'RIFF', 4) === 0 && substr($data, 8, 4) === 'WEBP')
            : (strncmp($data, "\xFF\xD8\xFF", 3) === 0);
        if (!$triMagicOk) {
            @unlink($cacheFile);
        } else {
            header('Content-Type: ' . ($fmt === 'webp' ? 'image/webp' : 'image/jpeg'));
            header('Cache-Control: public, max-age=2592000');
            if ($triFmtAuto) { header('Vary: Accept'); }
            header('X-Tri3-Img: cache');
            echo $data;
            exit;
        }
    }
}

/* 下载原图（CDN 鉴权签名只在此处进行，保证上面的磁盘缓存键稳定命中） */
$fetchUrl = $src;
if ($dogyunKey !== '' && $dogyunDomain !== '' && strpos($src, $dogyunDomain) !== false) {
    $fetchUrl = dogyun_sign_url($src, $dogyunKey, $dogyunUid, $dogyunExpire);
}
$triReferer = trim((string) tri_img_conf('siteUrl', ''));
if ($triReferer === '') {
    $triReferer = 'http' . ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
if (substr($triReferer, -1) !== '/') { $triReferer .= '/'; }
$ctx = stream_context_create(array(
    'http' => array(
        'timeout' => 15,
        'header'  => "User-Agent: TriM3-Img/2.0\r\nAccept: image/webp,image/*,*/*;q=0.8\r\nReferer: " . $triReferer . "\r\n",
    ),
));
$data = @file_get_contents($fetchUrl, false, $ctx);
if ($data === false || $data === '') {
    header('Location: ' . $fetchUrl);
    exit;
}

/* GD 解码 */
$triInfo = @getimagesizefromstring($data);
if ($triInfo && !empty($triInfo[0]) && !empty($triInfo[1]) && function_exists('tri3_img_dims_put')) {
    @tri3_img_dims_put($src, (int) $triInfo[0], (int) $triInfo[1]);
}
if ($triInfo && ((int) $triInfo[0] * (int) $triInfo[1] * 4 > 220 * 1024 * 1024)) {
    @mkdir($cacheDir, 0755, true);
    tri_img_cache_put($cacheFile, '302:' . $fetchUrl);
    header('Location: ' . $fetchUrl);
    exit;
}
$out = tri3_img_transform($data, $w, $crop, $q, $fmt);
if ($out === '') {
    header('Location: ' . $fetchUrl);
    exit;
}
$mime = ($fmt === 'webp') ? 'image/webp' : 'image/jpeg';

/* 输出 + 写缓存 */
if ($out !== '') {
    @mkdir($cacheDir, 0755, true);
    /* 容量清理改成概率触发（约 1/40 次未命中）：原来每个 miss 都全目录 glob
       + 全量 stat + 排序，缓存贴顶时每个请求都在做几千次系统调用。 */
    if (!is_file($cacheFile) && mt_rand(1, 40) === 1) {
        $triFiles = @glob($cacheDir . '*');
        if (is_array($triFiles) && count($triFiles) > $cacheMax) {
            usort($triFiles, function ($a, $b) { return filemtime($a) - filemtime($b); });
            $toDel = count($triFiles) - $cacheMax;
            foreach (array_slice($triFiles, 0, $toDel) as $triOld) { @unlink($triOld); }
        }
    }
    tri_img_cache_put($cacheFile, $out);
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=2592000');
    if ($triFmtAuto) { header('Vary: Accept'); }
    header('X-Tri3-Img: live');
    echo $out;
    exit;
}
header('Location: ' . $fetchUrl);
exit;
