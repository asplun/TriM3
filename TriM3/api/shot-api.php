<?php
/**
 * TriM3 友链截图缓存代理
 *
 * GET  ?url=站点     返回网站截图：服务端缓存到 cache/shot/，超过「截图缓存天数」自动重新拉取
 * POST do=clear      清空全部截图缓存（需管理员登录，同源校验）
 *
 * 拉取顺序：thum.io（主）→ mshots（降级）；失败返回 1x1 透明占位，前端走渐变兜底
 */

require_once dirname(__FILE__, 5) . '/config.inc.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}
if (!defined('__TYPECHO_ROOT_URL__')) {
    $triScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('__TYPECHO_ROOT_URL__', $triScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}
\Widget\Init::alloc();

$triCacheDir = dirname(__DIR__) . '/cache/shot';
if (!is_dir($triCacheDir)) { @mkdir($triCacheDir, 0755, true); }

/* ================= 清缓存（管理员 + CSRF） ================= */
if (isset($_POST['do']) && $_POST['do'] === 'clear') {
    header('Content-Type: application/json; charset=utf-8');
    $triUser = \Widget\Widget::widget('Widget_User');
    if (!$triUser->hasLogin() || !$triUser->pass('administrator')) {
        echo json_encode(array('ok' => false, 'msg' => '无权限'));
        exit;
    }
    if (!tri3_csrf_ok()) {
        echo json_encode(array('ok' => false, 'msg' => '非法来源'));
        exit;
    }
    $triN = 0;
    foreach (glob($triCacheDir . '/*.jpg') ?: array() as $triF) {
        if (@unlink($triF)) { $triN++; }
    }
    echo json_encode(array('ok' => true, 'cleared' => $triN));
    exit;
}

/* ================= 获取截图（本地缓存） ================= */
$triUrl = isset($_GET['url']) ? trim((string) $_GET['url']) : '';
if ($triUrl === '') { $triUrl = isset($_SERVER['HTTP_REFERER']) ? '' : ''; }
if (!preg_match('#^https?://#i', $triUrl)) {
    $triUrl = 'http://' . $triUrl;
}
if (!filter_var($triUrl, FILTER_VALIDATE_URL)) {
    http_response_code(404);
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

/* 只允许公网 http(s)：拒绝内网/本机/保留地址，避免被当成内网探测跳板 */
$triHost = strtolower((string) parse_url($triUrl, PHP_URL_HOST));
if ($triHost === '' || !preg_match('/^(?=.{4,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $triHost)
    || preg_match('/^(localhost|.*\.local|\.internal)$/', $triHost)
    || filter_var($triHost, FILTER_VALIDATE_IP)
        && !filter_var($triHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    http_response_code(404);
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

$triKey = md5($triUrl);
$triFile = $triCacheDir . '/' . $triKey . '.jpg';

/* 缓存天数：后台「截图缓存(天)」设置，默认 5 */
$triDays = 5;
try {
    $o = \Typecho\Widget::widget('Widget_Options');
    if (isset($o->shotCacheDays) && (int) $o->shotCacheDays > 0) {
        $triDays = (int) $o->shotCacheDays;
    }
} catch (Throwable $e) {}

/* 缓存文件必须是真图片：上游偶尔返回 HTML 错误页，落盘后会一直当成截图发给前端 */
$triIsImg = function ($f) {
    $h = @file_get_contents($f, false, null, 0, 16);
    if (!is_string($h) || $h === '') { return false; }
    return strncmp($h, "\xFF\xD8\xFF", 3) === 0
        || strncmp($h, "\x89PNG\r\n\x1A\n", 8) === 0
        || (strncmp($h, 'RIFF', 4) === 0 && substr($h, 8, 4) === 'WEBP');
};

if (file_exists($triFile) && (time() - filemtime($triFile)) < $triDays * 86400) {
    if ($triIsImg($triFile)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        header('X-Tri3-Shot: cache');
        readfile($triFile);
        exit;
    }
    @unlink($triFile); /* 坏缓存（HTML/空文件）直接丢弃重建 */
}

/* 过期/无缓存：不在请求里等上游 —— 先把旧图或占位发给用户，刷新排到响应之后。
   为什么：这个接口被打了 5000+ 次，原来每次都同步等上游（timeout 15s、两级降级最多 30s），
   一个请求霸占一个 PHP-FPM worker；友链页一次触发十几张，池子（pm.max_children=50）
   被占满后别人的页面请求就要排队（探针实测：PHP 自身只渲染 19ms，却等了 0.9s）。 */
$triPlaceholder = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

if (file_exists($triFile) && $triIsImg($triFile)) { /* 有旧的先给旧的，绝不让用户等 */
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=3600');
    header('X-Tri3-Shot: stale');
    readfile($triFile);
} else {                                        /* 首次访问：透明占位，前端渐变兜底 */
    http_response_code(404);
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, max-age=0');
    header('X-Tri3-Shot: pending');
    echo $triPlaceholder;
}

/* 刷新只在「同源来路」时排队，避免这个接口被当成任意站点的抓取代理；
   同一 URL 加锁，避免多个访客同时触发同一张图 */
$triRef = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
if (strpos($triRef, '://' . ($_SERVER['HTTP_HOST'] ?? '')) === false) { exit; }
$triLock = $triFile . '.lock';
if (file_exists($triLock) && (time() - filemtime($triLock)) < 60) { exit; }
@touch($triLock);
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@set_time_limit(8); /* v1.2.27: was 30s worst case */
$triT0 = microtime(true);
$triCtx = stream_context_create(array(
    'http' => array('timeout' => 5, 'ignore_errors' => true),
    'ssl'  => array('verify_peer' => true, 'verify_peer_name' => true),
));
$triImg = @file_get_contents('https://image.thum.io/get/maxAge/24/width/400/allowJPG/' . $triUrl, false, $triCtx);
if (($triImg === false || strlen($triImg) < 800) && (microtime(true) - $triT0) < 3) {
    $triImg2 = @file_get_contents('https://s0.wp.com/mshots/v1/' . urlencode($triUrl) . '?w=400&h=300', false, $triCtx);
    if ($triImg2 !== false && strlen($triImg2) > 800) { $triImg = $triImg2; }
}
$triImgOk = false;
if (is_string($triImg) && strlen($triImg) > 800) {
    $triImgOk = strncmp($triImg, "\xFF\xD8\xFF", 3) === 0
        || strncmp($triImg, "\x89PNG\r\n\x1A\n", 8) === 0
        || (strncmp($triImg, 'RIFF', 4) === 0 && substr($triImg, 8, 4) === 'WEBP');
}
if ($triImgOk) {
    if (!is_dir($triCacheDir)) { @mkdir($triCacheDir, 0755, true); }
    tri3_atomic_write($triFile, $triImg);
    /* 数量上限：超过 1500 张时按 mtime 清掉最旧的一批（1/20 概率触发） */
    if (mt_rand(1, 20) === 1) {
        $triAll = @glob($triCacheDir . '/*.jpg');
        if (is_array($triAll) && count($triAll) > 1500) {
            usort($triAll, function ($a, $b) { return filemtime($a) - filemtime($b); });
            foreach (array_slice($triAll, 0, count($triAll) - 1500) as $triOld) { @unlink($triOld); }
        }
    }
}
@unlink($triLock);
exit;

/* 拉取失败：透明占位，前端显示渐变兜底 */
http_response_code(404);
header('Content-Type: image/gif');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
exit;
