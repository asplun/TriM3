<?php
/**
 * TriM3 · 图片固有尺寸表预热（命令行）
 *
 *   php /path/to/Typecho/usr/themes/TriM3/cli/warm-dims.php example.com
 *
 * 作用：把「列表页 + 相册全部分页 + 全部文章页」里的图逐张取一次尺寸，写进
 *       cache/img-dims.json，让前台 <img> 直接带上 width/height —— 布局在解码前
 *       就占好位置，加载不再引起回流。
 * 说明：站外图床同样收录；防盗链取不到的（如 tc.asplun.cn）留空，不猜。
 *       已经在表里的图会跳过，可以反复执行。
 */
if (PHP_SAPI !== 'cli') { exit('cli only'); }
require_once dirname(__DIR__) . '/lib/img-dims.php';

function tri3_warm_ctx($ref) {
    $h = "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36\r\nAccept: image/webp,image/*,*/*;q=0.8\r\n";
    if ($ref !== '') { $h .= "Referer: " . $ref . "\r\n"; }
    return stream_context_create(array('http' => array('timeout' => 12, 'ignore_errors' => true, 'header' => $h),
        'ssl' => array('verify_peer' => false, 'verify_peer_name' => false)));
}

function tri3_warm_album_page($pages) {
    foreach ($pages as $p) {
        if (strpos($p, 'album') !== false) { return $p; }
    }
    return '';
}

$host = isset($argv[1]) ? $argv[1] : 'example.com';
$site = 'https://' . $host;
$imrx = '#(?:data-src|data-srcset|src|href)="(https?://[^"\s]+?\.(?:jpg|jpeg|png|gif|webp|bmp|avif)(?:\?[^"\s]*)?)"#i';
$urls = array();
$add = function ($list) use (&$urls) { foreach ($list as $u) { $urls[str_replace('&amp;', '&', $u)] = 1; } };
$ctx = tri3_warm_ctx('');

/* v1.2.90：页面清单不再写死 slug —— 先抓首页，从主题输出的站内独立页链接里发现，
   再抓这些页面；文章页链接从任意已发现页面收集。单页改名（slug 变化）后自动跟上。 */
$pages = array('/');
$seenPages = array('/' => 1);
$home = @file_get_contents($site . '/?cb=' . mt_rand(), false, $ctx);
if (is_string($home) && $home !== '') {
    if (preg_match_all('#href="' . preg_quote($site, '#') . '(/index\.php/[A-Za-z0-9_\-]+\.html)"#', $home, $mp)) {
        foreach (array_unique($mp[1]) as $pu) {
            if (!isset($seenPages[$pu])) { $seenPages[$pu] = 1; $pages[] = $pu; }
        }
    }
}
echo "页面清单（自动发现）: " . implode(' ', $pages) . "\n";
$postLinks = array();
foreach ($pages as $p) {
    $h = @file_get_contents($site . $p . (strpos($p, '?') === false ? '?' : '&') . 'cb=' . mt_rand(), false, $ctx);
    if (!is_string($h) || $h === '') { continue; }
    if (preg_match_all($imrx, $h, $m)) { $add($m[1]); }
    if (preg_match_all('#href="(' . preg_quote($site, '#') . '/index\.php/archives/\d+/)"#', $h, $m2)) {
        foreach ($m2[1] as $pl) { $postLinks[$pl] = 1; }
    }
}
if ($postLinks) { echo "发现文章页 " . count($postLinks) . " 个\n"; }
foreach (array_slice(array_keys($postLinks), 0, 300) as $a) {
    $ah = @file_get_contents($a . '?cb=' . mt_rand(), false, $ctx);
    if (is_string($ah) && $ah !== '' && preg_match_all($imrx, $ah, $m3)) { $add($m3[1]); }
}
foreach (array(1, 2, 3, 4, 5, 6, 7, 8) as $a) {
    $ap = tri3_warm_album_page($pages);
    if ($ap === '') { break; }
    $h = @file_get_contents($site . $ap . '?album=' . $a . '&cb=' . mt_rand(), false, $ctx);
    if (!is_string($h) || $h === '') { continue; }
    if (preg_match_all($imrx, $h, $m4)) { $add($m4[1]); }
}
echo "共发现图片 " . count($urls) . " 个\n";

$seen = array(); $new = 0; $cached = 0; $bad = 0;
foreach (array_keys($urls) as $u) {
    $k = tri3_img_dims_key($u);
    if ($k === '' || isset($seen[$k])) { continue; }
    $seen[$k] = 1;
    if (tri3_img_dims($u) !== null) { $cached++; continue; }
    $done = false;
    foreach (array('https://' . $host . '/', '') as $ref) {
        $data = @file_get_contents($u, false, tri3_warm_ctx($ref));
        if (is_string($data) && strlen($data) > 100) {
            $i = @getimagesizefromstring($data);
            if ($i && !empty($i[0]) && !empty($i[1])) { tri3_img_dims_put($u, (int) $i[0], (int) $i[1]); $new++; $done = true; break; }
        }
    }
    if (!$done) { $bad++; }
}
echo "DONE new=$new cached=$cached fail=$bad 表内共 " . count(tri3_img_dims_map()) . " 条\n";
