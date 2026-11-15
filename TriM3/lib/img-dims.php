<?php
/**
 * TriM3 · 图片固有尺寸表
 *
 * 根因：列表图不带固有尺寸，浏览器无法在图片解码前预留高度，于是每加载一张图
 * 就把后面的内容往下推。位置一直在变，「该不该加载」就只能靠运行时反复猜
 * （提前量、位置复判、布局稳定门……），而这些都是在给同一件事打补丁。
 *
 * 这里把尺寸记一次、长期复用，让 <img> 直接带上 width/height：
 *   - 布局在解码前就占好位置，加载顺序不再引起回流，位置判定一次就成立
 *   - 相册网格/瀑布流可以一次排好，不必等图回来才知道该占多高
 *
 * 尺寸来源：图片代理 api/img.php 解码时顺手记录（它本来就要 getimagesizefromstring）
 *           + 一次性预热遍历（把已发布内容里的图都过一遍）。
 * 存储：cache/img-dims.json，键=图片路径（去掉 imageView2 与鉴权 query），值=[宽,高]。
 */

function tri3_img_dims_file(): string
{
    return __DIR__ . '/../cache/img-dims.json';
}

/** 同一张图的各个尺寸档共用一条记录：键取路径，去掉 imageView2/签名 query */
function tri3_img_dims_key(string $src): string
{
    $src = trim($src);
    if ($src === '') {
        return '';
    }
    $p = @parse_url($src);
    if (is_array($p) && !empty($p['path'])) {
        // 走主题代理的 URL（api/img.php?src=真实图）要还原成真实图，否则所有代理图同一个键
        if (strpos($p['path'], 'api/img.php') !== false && !empty($p['query'])) {
            parse_str($p['query'], $q);
            if (!empty($q['src'])) {
                return tri3_img_dims_key((string) $q['src']);
            }
        }
        // 带域名：站外图床可能有同名路径，只按 path 会串图
        $host = isset($p['host']) ? strtolower((string) $p['host']) : '';
        return $host === '' ? (string) $p['path'] : $host . (string) $p['path'];
    }
    return $src;
}

function &tri3_img_dims_ref(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = array();
    $f = tri3_img_dims_file();
    if (is_file($f)) {
        $raw = @file_get_contents($f);
        if (is_string($raw) && $raw !== '') {
            $j = @json_decode($raw, true);
            if (is_array($j)) {
                $map = $j;
            }
        }
    }
    return $map;
}

function tri3_img_dims_map(): array
{
    $m = &tri3_img_dims_ref();
    return $m;
}

/** 返回 array(宽, 高)；未知返回 null */
function tri3_img_dims(string $src)
{
    $k = tri3_img_dims_key($src);
    if ($k === '') {
        return null;
    }
    $map = tri3_img_dims_map();
    if (!isset($map[$k]) || !is_array($map[$k]) || count($map[$k]) < 2) {
        return null;
    }
    $w = (int) $map[$k][0];
    $h = (int) $map[$k][1];
    if ($w < 1 || $h < 1) {
        return null;
    }
    return array($w, $h);
}

/** 记录一张图的尺寸（只写变化，避免每张图都落盘） */
function tri3_img_dims_put(string $src, int $w, int $h): void
{
    if ($w < 1 || $h < 1) {
        return;
    }
    $k = tri3_img_dims_key($src);
    if ($k === '') {
        return;
    }
    $map = tri3_img_dims_map();
    if (isset($map[$k]) && (int) $map[$k][0] === $w && (int) $map[$k][1] === $h) {
        return;
    }
    $m = &tri3_img_dims_ref();
    $m[$k] = array($w, $h);
    $f = tri3_img_dims_file();
    $dir = dirname($f);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $triJson = json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (function_exists('tri3_atomic_write')) {
        tri3_atomic_write($f, $triJson);
    } else {
        /* 本文件会被不引导 Typecho 的 api/img.php 直接 include，不能假定 helpers 已加载 */
        $triTmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($triTmp, $triJson) === false || !@rename($triTmp, $f)) {
            @unlink($triTmp);
            @file_put_contents($f, $triJson);
        }
    }
}

/** <img> 上的固有尺寸属性：拿到就输出，拿不到就什么都不加（不猜） */
function tri3_img_size_attr(string $src): string
{
    $d = tri3_img_dims($src);
    if ($d === null) {
        return '';
    }
    return ' width="' . $d[0] . '" height="' . $d[1] . '"';
}
