<?php
/**
 * TriM3 · 内置 Meting API 代理（自 0True 移植，MIT）
 *
 * 端点在服务器端用 Metowolf/Meting 库请求音乐平台，返回歌曲元数据；
 * 音频走本地 proxy 转发（带 Referer/Range），彻底规避前端 CORS 与防盗链。
 *
 * 端点：
 *   ?server=netease&type=playlist&id=歌单ID   → 歌单元数据（url/pic 懒加载）
 *   ?server=netease&type=url&id=歌曲ID        → {"url":"本地proxy地址"}
 *   ?server=netease&type=proxy&url=base64     → 流式音频（支持 Range 206）
 *   ?server=netease&type=lrc&id=歌曲ID        → LRC 歌词文本
 *   ?server=netease&type=pic&id=歌曲ID        → 302 重定向到专辑封面
 *   ?server=netease&type=song&id=歌曲ID       → 单曲完整信息
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$server = isset($_GET['server']) ? strtolower(trim($_GET['server'])) : '';
$type   = isset($_GET['type'])   ? strtolower(trim($_GET['type']))   : '';
$id     = isset($_GET['id'])     ? trim($_GET['id'])                 : '';

if ($server === 'qq') $server = 'tencent';

$triCfgFile = dirname(__DIR__, 4) . '/config.inc.php';
if (!defined('__TYPECHO_ROOT_DIR__') && file_exists($triCfgFile)) {
    try { require_once $triCfgFile; } catch (Throwable $e) {}
}

function tri3_meting_site_origin(): string
{
    try {
        if (!defined('__TYPECHO_ROOT_DIR__')) { return ''; }
        $db = \Typecho\Db::get();
        if (!$db) { return ''; }
        $row = $db->fetchRow($db->select()->from('table.options')->where('name = ?', 'siteUrl'));
        $url = trim((string) ($row['value'] ?? ''));
        if ($url === '') { return ''; }
        $p = parse_url($url);
        if (!isset($p['scheme'], $p['host'])) { return ''; }
        return strtolower((string) $p['scheme']) . '://' . strtolower((string) $p['host']);
    } catch (Throwable $e) {
        return '';
    }
}

$triSiteOrigin = tri3_meting_site_origin();
$triRequestPath = strtok($_SERVER['REQUEST_URI'], '?');
$localBase = ($triSiteOrigin !== '' ? $triSiteOrigin : 'http://' . $_SERVER['HTTP_HOST']) . $triRequestPath;

/* ============================================================
 * v1.2.24 代理落盘缓存
 * 为什么：这个接口每次都要占着一个 PHP worker 去等上游（实测 0.15~0.35s/次）。
 * 爬虫重复打同一参数时（日志里单 IP 打过 1554 次），worker 池被占满，
 * 别人的正常页面请求在 PHP 启动之前就要排队 —— 页面自身只渲染 19ms 却要等 0.9s。
 * 做法：GET + type=pic|lrc|song|playlist|search 的响应（含 302 的 Location）落盘 1 天；
 *       音频流（type=proxy、带 url=）完全不碰，Range 语义不变。
 * ============================================================ */
$tri3CacheDir = __DIR__ . '/../cache/meting';
$tri3Type = isset($_GET['type']) ? (string) $_GET['type'] : '';
$tri3Cacheable = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET')
    && !isset($_GET['url'])
    && preg_match('/^(pic|lrc|song|playlist|search)$/', $tri3Type) === 1;
if ($tri3Cacheable) {
    if (!is_dir($tri3CacheDir)) { @mkdir($tri3CacheDir, 0755, true); }
    $tri3CacheFile = $tri3CacheDir . '/' . md5((string) ($_SERVER['QUERY_STRING'] ?? '')) . '.json';
    if (is_file($tri3CacheFile) && (time() - filemtime($tri3CacheFile)) < 86400) {
        $tri3Hit = json_decode((string) @file_get_contents($tri3CacheFile), true);
        if (is_array($tri3Hit) && array_key_exists('b', $tri3Hit)) {
            foreach ((array) ($tri3Hit['h'] ?? array()) as $tri3H) { @header($tri3H, true); }
            @header('X-Tri3-Proxy-Cache: hit');
            if ($tri3Hit['b'] !== '') { echo $tri3Hit['b']; }
            exit;
        }
    }
    ob_start();
    register_shutdown_function(function () use ($tri3CacheFile) {
        $tri3Code = (int) http_response_code();
        $tri3Body = (string) ob_get_contents();
        if (!in_array($tri3Code, array(200, 301, 302), true) || strlen($tri3Body) > 524288) { return; }
        $tri3Keep = array();
        foreach ((array) headers_list() as $tri3H) {
            if (stripos($tri3H, 'Location:') === 0 || stripos($tri3H, 'Content-Type:') === 0 || stripos($tri3H, 'Cache-Control:') === 0) {
                $tri3Keep[] = $tri3H;
            }
        }
        $tri3Payload = json_encode(array('h' => $tri3Keep, 'b' => $tri3Body));
        if (function_exists('tri3_atomic_write')) {
            tri3_atomic_write($tri3CacheFile, $tri3Payload);
        } else {
            /* 本接口可能只引导了 config.inc.php，helpers 未必加载 */
            $tri3Tmp = $tri3CacheFile . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tri3Tmp, $tri3Payload) === false || !@rename($tri3Tmp, $tri3CacheFile)) {
                @unlink($tri3Tmp);
                @file_put_contents($tri3CacheFile, $tri3Payload);
            }
        }
        /* 缓存数量上限：meting 响应体可达数百 KB，超过 4000 个按 mtime 清最旧的 */
        if (mt_rand(1, 20) === 1) {
            $tri3All = @glob($tri3CacheDir . '/*.json');
            if (is_array($tri3All) && count($tri3All) > 4000) {
                usort($tri3All, function ($a, $b) { return filemtime($a) - filemtime($b); });
                foreach (array_slice($tri3All, 0, count($tri3All) - 4000) as $tri3Old) { @unlink($tri3Old); }
            }
        }
    });
}


/* ─── 代理音频：带 Referer + Range 流式转发 ─── */
if ($type === 'proxy') {
    /* 添加 CORS 头以支持音频可视化 */
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Range, Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    /* VIP 音乐：读取 QQMusicCookie */
    $triCookie = tri3_read_theme_cookie($server);
    $encodedUrl = isset($_GET['url']) ? $_GET['url'] : '';
    $targetUrl = base64_decode($encodedUrl);
    if (!$targetUrl || !preg_match('/^https?:\/\//', $targetUrl)) {
        http_response_code(400);
        exit;
    }

    $triAllow = array('163.com', '126.net', 'qq.com', 'gtimg.cn', 'tc.qq.com', 'music.tc.qq.com', 'qqmusic.qq.com', 'aqmusic.tc.qq.com');
    $triHostOk = function ($triU) use ($triAllow) {
        $triH = parse_url($triU, PHP_URL_HOST);
        $triH = $triH !== null ? strtolower(trim($triH)) : '';
        if ($triH === '' || filter_var($triH, FILTER_VALIDATE_IP)) { return false; }
        foreach ($triAllow as $triSfx) {
            if ($triH === $triSfx || substr($triH, -(strlen($triSfx) + 1)) === '.' . $triSfx) { return true; }
        }
        return false;
    };
    if (!$triHostOk($targetUrl)) {
        http_response_code(403);
        exit;
    }

    /* --- SSRF防护2：关闭自动跳转，逐跳手动跟随并重新校验域名 ---
       否则白名单域名（如 *.qq.com）可用 302 把请求引向内网地址。 */
    /* 说明（v1.2.30）：原先这里还有一段「逐跳 HEAD 解析真实地址 → 302 直连」的代码，
       其 302 分支被 `if (false && ...)` 永久关闭，HEAD 循环每首歌仍要额外跑 1~5 次请求
       （连接超时 4s、总超时 8s），纯属浪费。已删除，直接走下面的流式代理。 */

    $hasRange = isset($_SERVER['HTTP_RANGE']);
    $responseHeaders = '';
    $httpCode = 0;
    $body = '';
    $success = false;
    /* 添加 CORS 头支持音频可视化 */
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Range, Content-Type');

    for ($triHop = 0; $triHop <= 5; $triHop++) {
        if (!$triHostOk($targetUrl)) {
            http_response_code(403);
            exit;
        }
        $referer = (strpos($targetUrl, 'qq.com') !== false || strpos($targetUrl, 'gtimg.cn') !== false)
            ? 'https://y.qq.com/'
            : 'https://music.163.com/';
        $headers = array(
            'Referer: ' . $referer,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        );
        if ($hasRange) {
            $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }
        /* VIP 音乐：传递 QQMusicCookie */
        if (isset($triCookie) && $triCookie !== '') {
            $headers[] = 'Cookie: ' . $triCookie;
        }

        $ch = curl_init($targetUrl);
        $responseHeaders = '';
        curl_setopt_array($ch, array(
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 120,  /* 整首歌的中继仍需长连接 */
            /* v1.2.26：上游卡住时立刻自杀。实测回源最长挂 78.6 秒（meting 69 秒），
               50 个 PHP worker 被几个播放请求占满后，导航请求排队 → 浏览器中止(499) →
               Swup 收到 Failed to fetch → 整页重载。低速阈值让僵尸连接 15 秒内结束，
               正常播放（>=1KB/s）完全不受影响。 */
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $responseHeaders .= $header;
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) {
                $triCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($triCode >= 200 && $triCode < 300) { echo $chunk; }
                return strlen($chunk);
            },
        ));

        ob_start();
        $success = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = ob_get_clean();
        unset($ch); /* PHP 8.5：curl_close() 已弃用 */

        if ($httpCode < 300 || $httpCode >= 400) { break; }

        if (!preg_match('/^Location:\s*(.+)$/im', $responseHeaders, $triLoc)) { break; }
        $triNext = trim($triLoc[1]);
        if (!preg_match('#^https?://#i', $triNext)) {
            $triBase = preg_replace('#^(https?://[^/]+).*$#i', '$1', $targetUrl);
            if (strpos($triNext, '/') !== 0) { $triNext = '/' . $triNext; }
            $triNext = $triBase . $triNext;
        }
        if (!$triHostOk($triNext)) {
            http_response_code(403);
            exit;
        }
        $targetUrl = $triNext;
    }

    header('Content-Type: audio/mpeg');
    header('Accept-Ranges: bytes');
    header('Cache-Control: public, max-age=3600');

    if ($hasRange && $httpCode === 206) {
        if (preg_match('/Content-Range:\s*([^\r\n]+)/i', $responseHeaders, $m)) {
            header('Content-Range: ' . trim($m[1]));
        }
        if (preg_match('/Content-Length:\s*(\d+)/i', $responseHeaders, $m)) {
            header('Content-Length: ' . trim($m[1]));
        }
        http_response_code(206);
    } elseif (preg_match('/Content-Length:\s*(\d+)/i', $responseHeaders, $m)) {
        header('Content-Length: ' . trim($m[1]));
    }

    echo $body;
    exit;
}

/* ─── 加载 Meting 库 ─── */
$metingFile = dirname(__DIR__) . '/lib/Meting.php';
if (!file_exists($metingFile)) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['error' => 'Meting.php not found']);
    exit;
}
require_once $metingFile;

use Metowolf\Meting;

/* 服务端读取主题设置中对应平台的 Cookie（VIP 音乐播放，不暴露给前端）
 * 网易云 -> metingCookieNetease（旧字段 metingCookie 兼容回退）
 * QQ音乐 -> metingCookieTencent
 * 通过 Typecho 引导加载 Db 单例读取 options 表（兼容官方 config.inc.php 原生格式）
 */

/* ── v1.2.51：封面统一本地转发 ──
   原实现把已解析出的封面地址 302 甩给浏览器，跨域响应无 CORS 头，
   任何 fetch() 都会失败（TypeError: Failed to fetch / net::ERR_FAILED），
   且这些注定失败的请求持续占压连接。这里改成服务端取图后同源直出。
   取不到时才退回 302，保证封面不丢。 */
if (!function_exists('tri3_meting_pic_out')) {
    function tri3_meting_pic_out($src) {
        $src = tri3_meting_https($src);
        $body = false; $mime = ''; $code = 0;
        try {
            $ch = curl_init($src);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0',
                CURLOPT_REFERER        => 'https://y.qq.com/',
            ));
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            unset($ch);
        } catch (Throwable $e) {}
        if ($body !== false && strlen((string) $body) > 0 && $code === 200) {
            if ($mime === '' || stripos($mime, 'image') === false) { $mime = 'image/jpeg'; }
            if (!headers_sent()) {
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . strlen((string) $body));
                header('Cache-Control: public, max-age=86400');
                header('X-Tri3-Pic: proxy');
            }
            echo $body;
            exit;
        }
        header('Cache-Control: public, max-age=86400');
        header('Location: ' . $src, true, 302);
        exit;
    }
}

function tri3_read_theme_cookie(string $triServer): string
{
    /* 0) 先引导 Typecho（定义 __TYPECHO_ROOT_DIR__、加载 Db），供插件/主题设置读取 */
    $cfgFile = dirname(__DIR__, 4) . '/config.inc.php';
    if (!defined('__TYPECHO_ROOT_DIR__') && file_exists($cfgFile)) {
        try { require_once $cfgFile; } catch (Throwable $e) {}
    }
    /* 1) QQMusicCookie 插件优先（集中管理 Cookie，VIP 播放用） */
    $triPlg = dirname(__DIR__, 4) . '/usr/plugins/QQMusicCookie/Plugin.php';
    if (defined('__TYPECHO_ROOT_DIR__') && file_exists($triPlg)) {
        try {
            /* 独立 API 环境下显式加载 Typecho 插件接口（autoload 不覆盖） */
            $triIface = dirname(__DIR__, 4) . '/var/Typecho/Plugin/PluginInterface.php';
            if (file_exists($triIface)) { require_once $triIface; }
            require_once $triPlg;
            if (class_exists('\\TypechoPlugin\\QQMusicCookie\\Plugin')) {
                $ck = \TypechoPlugin\QQMusicCookie\Plugin::getCookie($triServer);
                if ($ck !== '') { return $ck; }
            }
        } catch (Throwable $e) {}
    }
    /* 2) 主题设置（原有逻辑，插件未启用/未配置时回退） */
    if (!defined('__TYPECHO_ROOT_DIR__')) { return ''; }
    try {
        require_once $cfgFile;
        $db = \Typecho\Db::get();
        if (!$db) { return ''; }
        $rows = $db->fetchAll($db->select('value')->from('table.options')->where('name = ?', 'theme:TriM3'));
        $val = (isset($rows[0]['value']) && $rows[0]['value'] !== '') ? $rows[0]['value'] : '';
        if ($val === '') { return ''; }
        $data = json_decode((string) $val, true);
        if (!is_array($data)) { return ''; }
        if ($triServer === 'tencent') {
            $ck = trim((string) ($data['metingCookieTencent'] ?? ''));
            if ($ck !== '') { return $ck; }
        }
        $ck = trim((string) ($data['metingCookieNetease'] ?? ''));
        if ($ck !== '') { return $ck; }
        return trim((string) ($data['metingCookie'] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

try {
    $api = new Meting($server);
    $api->format(true);
    /* VIP 音乐：服务端按平台附加对应 Cookie（网易云 / QQ 音乐） */
    $triCookie = tri3_read_theme_cookie($server);
    if ($triCookie !== '') {
        $api->cookie($triCookie);
    }
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
    echo json_encode(['error' => 'init failed', 'message' => $e->getMessage()]);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
$triOrigin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
if ($triOrigin !== '' && $triOrigin === $triSiteOrigin) {
    header('Access-Control-Allow-Origin: ' . $triOrigin);
}

/* ─── 用本地代理包装音频地址（带 Referer） ─── */
/* 强制 HTTPS：网易云等封面源可能返回 http://，HTTPS 站点下会被 Mixed Content 拦截 */
function tri3_meting_https($u) {
    if (is_string($u) && stripos($u, 'http://') === 0) {
        return 'https://' . substr($u, 7);
    }
    return $u;
}

function tri_proxy_url($url, $server, $localBase) {
    if (!$url) return '';
    return $localBase . '?server=' . $server . '&type=proxy&url=' . base64_encode($url);
}

/* ─── 单曲：完整信息（url/pic/lrc 端点） ─── */
if ($type === 'song') {
    $rawSong = $api->song($id);
    $data = json_decode($rawSong, true);
    /* 构造封面 URL：Meting 返回的 data[0].album.pmid 可用于拼出封面图 */
    if (is_array($data) && isset($data[0]['album']['pmid'])) {
        $pmid = $data[0]['album']['pmid'];
        $data[0]['pic'] = 'https://y.gtimg.cn/music/photo_new/T002R300x300M000' . $pmid . '.jpg';
    }
    $urlData = json_decode($api->url($id, 320), true);
    $url = $urlData['url'] ?? '';
    /* tencent VIP：当 url 为空时，使用 vkey.GetVkeyServer API 获取 */
    if (empty($data) || $url === '') {
        $triCookie = tri3_read_theme_cookie($server);
        if ($server === 'tencent' && $triCookie !== '') {
            $vkeyBody = json_encode(array(
                'req_1' => array(
                    'module' => 'vkey.GetVkeyServer',
                    'method' => 'CgiGetVkey',
                    'param' => array(
                        'guid' => '10000',
                        'songmid' => array($id),
                        'songtype' => array(0),
                        'uin' => '975991504',
                        'loginflag' => 1,
                        'platform' => '20',
                    ),
                ),
            ));
            $ch = curl_init('https://u.y.qq.com/cgi-bin/musicu.fcg');
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $vkeyBody,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Cookie: ' . $triCookie,
                    'Referer: https://y.qq.com/',
                ),
                CURLOPT_ENCODING => '',
            ));
            $vkeyResp = curl_exec($ch);
            curl_close($ch);
            $vkeyData = json_decode($vkeyResp, true);
            $purl = $vkeyData['req_1']['data']['midurlinfo'][0]['purl'] ?? '';
            if ($purl !== '') {
                $url = $purl;
                /* 构造 song info */
                $data = array(array(
                    'id' => $id,
                    'name' => $id,
                    'artist' => '',
                    'album' => '',
                    'pic' => '',
                    'url_id' => $id,
                ));
            }
        }
    }
    if (empty($data)) {
        http_response_code(404);
        echo json_encode(['error' => 'song_not_found']);
        exit;
    }
    $song = $data[0];
    /* v1.2.75：同时给出上游 https 直链。前端用它 + crossOrigin=anonymous 播放，
       Web Audio 才读得到真实频谱（直链带 CORS，已实测可读）；下面的同源代理是
       跨域 302，接进 AudioContext 图会被置零甚至静音，只作退路。 */
    $direct = tri3_meting_https($url);
    if ($url) { $url = tri_proxy_url($url, $server, $localBase); }
    $songIdForPic = $song['url_id'] ?? $song['id'] ?? $id;
    /* 优先返回真实封面直链（CDN），无直链时主动跟随一次 302 拿到真实封面地址 */
    $picReal = $song['pic'] ?? '';
    if ($picReal === '') {
        $picProbe = $localBase . '?server=' . $server . '&type=pic&id=' . urlencode($songIdForPic);
        $ch = curl_init($picProbe);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        curl_exec($ch);
        $redir = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        unset($ch); /* PHP 8.5：curl_close() 已弃用 */
        if (is_string($redir) && $redir !== '' && preg_match('#^https?://#i', $redir)) {
            $picReal = tri3_meting_https($redir);
        }
    }
    $pic = $picReal !== ''
        ? $picReal
        : $localBase . '?server=' . $server . '&type=pic&id=' . urlencode($songIdForPic);
    $lrcId = $song['lyric_id'] ?? $id;
    $lrc = $localBase . '?server=' . $server . '&type=lrc&id=' . urlencode($lrcId);
    echo json_encode([
        'title'  => $song['name'] ?? '',
        'artist' => implode('/', $song['artist'] ?? []),
        'url'    => $url,
        'direct' => $direct,
        'pic'    => tri3_meting_https($pic),
        'lrc'    => $lrc
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ─── 音频 URL（本地代理包装） ─── */
if ($type === 'url') {
    $urlData = json_decode($api->url($id, 320), true);
    $url = $urlData['url'] ?? '';
    /* VIP fallback: Meting 拿不到 URL 时（VIP/需登录），直接调 QQ Music API 取 vkey */
    if ($url === '' && $server === 'tencent') {
        $triCookie = tri3_read_theme_cookie($server);
        if ($triCookie !== '') {
            $vkeyBody = json_encode(array(
                'req_1' => array(
                    'module' => 'vkey.GetVkeyServer',
                    'method' => 'CgiGetVkey',
                    'param' => array(
                        'guid' => '10000',
                        'songmid' => array($id),
                        'songtype' => array(0),
                        'loginflag' => 1,
                        'platform' => '20',
                    ),
                ),
            ));
            $ch = curl_init('https://u.y.qq.com/cgi-bin/musicu.fcg');
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $vkeyBody,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                    'Cookie: ' . $triCookie,
                    'Referer: https://y.qq.com/',
                ),
                CURLOPT_ENCODING => '',
            ));
            $vkeyResp = curl_exec($ch);
            curl_close($ch);
            $vkeyData = json_decode($vkeyResp, true);
            $purl = $vkeyData['req_1']['data']['midurlinfo'][0]['purl'] ?? '';
            if ($purl !== '') { $url = 'https://' . $purl; }
        }
    }
    /* v1.2.75：直链（上游 https，带 CORS）随同返回，播放器用它接 Web Audio 读真实频谱 */
    $direct = tri3_meting_https($url);
    if ($url) { $url = tri_proxy_url($url, $server, $localBase); }
    /* v1.2.76：带 vkey 票据的响应禁止任何中间层缓存（票据约 2 分钟过期） */
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    echo json_encode(['url' => $url, 'direct' => $direct, 'id' => $id], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ─── LRC 歌词文本 ─── */
if ($type === 'lrc' || $type === 'lyric') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=604800');
    if ($triOrigin !== '' && $triOrigin === $triSiteOrigin) {
        header('Access-Control-Allow-Origin: ' . $triOrigin);
    }
    try {
        $lrcData = json_decode($api->lyric($id), true);
        $lrc = $lrcData['lyric'] ?? ($lrcData['lrc'] ?? '');
        echo $lrc ? $lrc : '';
    } catch (Throwable $e) {
        echo '';
    }
    exit;
}

/* ─── 专辑封面：302 到真实封面 ─── */
if ($type === 'pic') {
    $realPic = '';
    $songData = null;
    try {
        $api->format(false);
        $songRaw = $api->song($id);
        $songData = json_decode($songRaw, true);
    } catch (Throwable $e) {}
    $api->format(true);

    if ($server === 'netease' && is_array($songData)) {
        if (isset($songData['songs'][0]['al']['picUrl'])) {
            $realPic = tri3_meting_https($songData['songs'][0]['al']['picUrl']);
        }
    } elseif ($server === 'tencent' && is_array($songData)) {
        $songInfo = (isset($songData['data'][0]) && is_array($songData['data'][0])) ? $songData['data'][0] : array();
        /* 1) 专辑封面直取 */
        if (!empty($songInfo['album']['pic'])) {
            $realPic = tri3_meting_https($songInfo['album']['pic']);
        } elseif (!empty($songInfo['album']['mid'])) {
            $realPic = 'https://y.gtimg.cn/music/photo_new/T002R300x300M000' . $songInfo['album']['mid'] . '.jpg?max_age=2592000';
        } else {
            /* 2) 腾讯官方 CgiGetTrackInfo 单曲封面（vs[1] 为 T062 高清封面，网页播放页同逻辑） */
            try {
                $qmid = (!empty($songInfo['mid'])) ? $songInfo['mid'] : $id;
                $tpayload = array('req' => array('module' => 'music.trackInfo.UniformRuleCtrl', 'method' => 'CgiGetTrackInfo', 'param' => array('mids' => array($qmid), 'types' => array(0), 'singer_pmid' => 1)));
                $tapi = 'https://u.y.qq.com/cgi-bin/musicu.fcg?format=json&platform=yqq.json&data=' . urlencode(json_encode($tpayload));
                $tch = curl_init($tapi);
                curl_setopt($tch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($tch, CURLOPT_TIMEOUT, 8);
                curl_setopt($tch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($tch, CURLOPT_USERAGENT, 'Mozilla/5.0');
                $tresp = curl_exec($tch);
                unset($tch); /* PHP 8.5：curl_close() 已弃用 */
                $tj = json_decode((string) $tresp, true);
                $track = (isset($tj['req']['data']['tracks'][0]) && is_array($tj['req']['data']['tracks'][0])) ? $tj['req']['data']['tracks'][0] : array();
                if (!empty($track['vs'][1])) {
                    $realPic = 'https://y.gtimg.cn/music/photo_new/T062R300x300M000' . $track['vs'][1] . '.jpg?max_age=2592000';
                } elseif (!empty($track['album']['mid'])) {
                    $realPic = 'https://y.gtimg.cn/music/photo_new/T002R300x300M000' . $track['album']['mid'] . '.jpg?max_age=2592000';
                }
            } catch (Throwable $e) {}
            /* 3) 搜索兜底：歌名+歌手均匹配才用，避免误用同名歌封面 */
            if ($realPic === '') {
                $songName = isset($songInfo['name']) ? $songInfo['name'] : '';
                if ($songName !== '') {
                    $singer = '';
                    if (isset($songInfo['singer']) && is_array($songInfo['singer'])) {
                        $snames = array();
                        foreach ($songInfo['singer'] as $sg) {
                            if (isset($sg['name']) && $sg['name'] !== '') { $snames[] = $sg['name']; }
                        }
                        $singer = implode(' ', $snames);
                    }
                    try {
                        $sr = json_decode($api->search($songName . ($singer !== '' ? ' ' . $singer : '')), true);
                        if (is_array($sr)) {
                            foreach ($sr as $item) {
                                if (empty($item['pic_id'])) { continue; }
                                $artistOk = ($singer === '');
                                if (!$artistOk) {
                                    $arts = is_array($item['artist']) ? $item['artist'] : array($item['artist']);
                                    foreach ($arts as $a) {
                                        if (mb_strpos((string) $a, $singer) !== false || mb_strpos($singer, (string) $a) !== false) { $artistOk = true; break; }
                                    }
                                }
                                $nameOk = mb_strpos((string) $item['name'], $songName) !== false || mb_strpos($songName, (string) $item['name']) !== false;
                                if ($artistOk && $nameOk) {
                                    $realPic = 'https://y.gtimg.cn/music/photo_new/T002R300x300M000' . $item['pic_id'] . '.jpg?max_age=2592000';
                                    break;
                                }
                            }
                        }
                    } catch (Throwable $e) {}
                }
            }
        }
    }

    /* 过滤腾讯占位封面（无专辑 mid 时 M000.jpg 灰图）；真实封面 T002R300x300M000<mid>.jpg 含 M000 但后跟 mid，不受影响 */
    if ($realPic && preg_match('/^https?:\/\//', $realPic) && !preg_match('#M000\.jpg(?:\?|$)#', $realPic)) {
        tri3_meting_pic_out($realPic);
    }
    $picData = json_decode($api->pic($id, 300), true);
    $pic = $picData['url'] ?? '';
    if ($pic && preg_match('/^https?:\/\//', $pic) && !preg_match('#M000\.jpg(?:\?|$)#', $pic)) {
        header('Cache-Control: public, max-age=86400'); /* 封面是静态资源：让浏览器/CDN 缓存，避免 Swup 还原快照时重打代理 */
        tri3_meting_pic_out($pic);
        /* exit 在函数内 */
    }
    http_response_code(404);
    exit;
}

/* ─── 歌单元数据（标题/介绍/标签/封面大图） ─── */
if ($type === 'playlist-detail') {
    $out = ['title' => '', 'description' => '', 'tags' => [], 'pic' => '', 'creator' => '', 'plays' => 0];
    try {
        if ($server === 'netease') {
            $ch = curl_init('https://music.163.com/api/v6/playlist/detail?id=' . urlencode($id));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => 'id=' . urlencode($id) . '&n=1000&s=0',
                CURLOPT_REFERER        => 'https://music.163.com/',
            ]);
            if ($triCookie !== '') { curl_setopt($ch, CURLOPT_COOKIE, $triCookie); }
            $resp = curl_exec($ch);
            unset($ch); /* PHP 8.5：curl_close() 已弃用 */
            $j = json_decode((string) $resp, true);
            if (isset($j['playlist']) && is_array($j['playlist'])) {
                $pl = $j['playlist'];
                $out['title'] = (string) ($pl['name'] ?? '');
                $out['description'] = trim((string) ($pl['description'] ?? ''));
                $out['tags'] = is_array($pl['tags'] ?? null)
                    ? array_values(array_filter(array_map('strval', $pl['tags'])))
                    : [];
                $out['pic'] = tri3_meting_https((string) ($pl['coverImgUrl'] ?? ''));
            }
        } elseif ($server === 'tencent') {
            $tapi = 'https://c.y.qq.com/qzone/fcg-bin/fcg_ucc_getcdinfo_byids_cp.fcg?type=1&json=1&utf8=1&onlysong=0&disstid=' . urlencode($id) . '&format=json';
            $ch = curl_init($tapi);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                CURLOPT_REFERER        => 'https://y.qq.com/',
            ]);
            if ($triCookie !== '') { curl_setopt($ch, CURLOPT_COOKIE, $triCookie); }
            $resp = curl_exec($ch);
            unset($ch); /* PHP 8.5：curl_close() 已弃用 */
            $j = json_decode((string) $resp, true);
            if (isset($j['cdlist'][0]) && is_array($j['cdlist'][0])) {
                $pl = $j['cdlist'][0];
                $out['title'] = (string) ($pl['dissname'] ?? '');
                /* QQ 歌单简介字段实为 desc（diss_desc 已不存在），多数歌单为空 */
                $out['description'] = trim((string) ($pl['desc'] ?? ($pl['diss_desc'] ?? '')));
                $out['pic'] = (string) ($pl['logo'] ?? '');
                /* 占位封面置空，前端回退到首曲封面 */
                if ($out['pic'] !== '' && (strpos($out['pic'], 'cover_playlist') !== false || strpos($out['pic'], 'cover_love') !== false)) {
                    $out['pic'] = '';
                }
                $out['creator'] = (string) ($pl['nickname'] ?? '');
                $out['plays'] = (int) ($pl['visitnum'] ?? 0);
                $tags = [];
                if (!empty($pl['dirinfo']) && is_array($pl['dirinfo'])) {
                    foreach ($pl['dirinfo'] as $d) {
                        if (isset($d['dirname']) && $d['dirname'] !== '') { $tags[] = (string) $d['dirname']; }
                    }
                }
                $out['tags'] = array_values(array_unique($tags));
            }
        }
    } catch (Throwable $e) {}
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}


/* ─── 歌单信息：返回封面、描述等元数据 ─── */
if ($type === 'playlist-info') {
    header('Content-Type: application/json; charset=utf-8');

    // First get song list for count and first song id
    $raw = $api->playlist($id);
    $data = json_decode($raw, true);
    $count = is_array($data) ? count($data) : 0;
    $firstSongId = '';
    if (!empty($data[0])) {
        $firstSongId = $data[0]['url_id'] ?? ($data[0]['id'] ?? '');
    }
    $coverUrl = $localBase . '?server=' . $server . '&type=pic&id=' . urlencode($firstSongId);

    $info = [
        'cover' => $coverUrl,
        'name' => '',
        'creator' => '',
        'playCount' => 0,
        'description' => '',
        'trackCount' => $count,
    ];

    if ($server === 'netease') {
        $ch = curl_init('https://music.163.com/api/playlist/detail?id=' . urlencode($id));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0', 'Referer: https://music.163.com/']
        ]);
        $resp = json_decode(curl_exec($ch), true);
        unset($ch); /* PHP 8.5：curl_close() 已弃用 */
        $p = $resp['playlist'] ?? ($resp['result'] ?? null);
        if ($p) {
            $info['name'] = $p['name'] ?? '';
            $info['cover'] = $p['coverImgUrl'] ?? $coverUrl;
            $info['creator'] = $p['creator']['nickname'] ?? '';
            $info['playCount'] = $p['playCount'] ?? 0;
            $info['description'] = $p['description'] ?? '';
            $info['trackCount'] = $p['trackCount'] ?? $count;
        }
    } elseif ($server === 'tencent') {
        $ch = curl_init('https://c.y.qq.com/qzone/fcg-bin/fcg_ucc_getcdinfo_byids_cp.fcg?type=1&json=1&utf8=1&onlysong=0&disstid=' . urlencode($id) . '&format=json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['User-Agent: Mozilla/5.0', 'Referer: https://y.qq.com/']
        ]);
        $resp = json_decode(curl_exec($ch), true);
        unset($ch); /* PHP 8.5：curl_close() 已弃用 */
        if (isset($resp['cdlist'][0]) && is_array($resp['cdlist'][0])) {
            $pl = $resp['cdlist'][0];
            $info['name'] = $pl['dissname'] ?? '';
            $info['cover'] = tri3_meting_https($pl['logo'] ?? $coverUrl);
            $info['creator'] = $pl['nickname'] ?? ($pl['nick'] ?? '');
            $info['description'] = $pl['desc'] ?? ($pl['diss_desc'] ?? '');
            $info['trackCount'] = $pl['song_cnt'] ?? $count;
        }
        $cd = $resp['data']['cdlist'][0] ?? ($resp['cdlist'][0] ?? null);
        if ($cd) {
            $info['name'] = $cd['title'] ?? ($cd['dissname'] ?? '');
            $info['cover'] = tri3_meting_https($cd['logo'] ?? $coverUrl);
            $info['creator'] = $cd['nickname'] ?? ($cd['nick'] ?? '');
            $info['playCount'] = (int) ($cd['visitnum'] ?? 0);
            $info['description'] = $cd['desc'] ?? ($cd['diss_desc'] ?? '');
            $info['trackCount'] = $cd['total_song_num'] ?? ($cd['song_cnt'] ?? $count);
        }
    }

    echo json_encode($info, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ─── 歌单：只返回元数据，url/pic 由前端按 id 懒加载 ─── */
if ($type === 'playlist') {
    if (function_exists('tri3_debug_log')) { tri3_debug_log('meting', "playlist server=$server id=$id"); }
    $raw = $api->playlist($id);
    $data = json_decode($raw, true);
    /* 修复：网易云 API 只返回部分 tracks，需要从 trackIds 获取全部 */
    if (function_exists('tri3_debug_log')) { tri3_debug_log('meting', "server=$server data_count=" . (is_array($data) ? count($data) : 0)); }
    if ($server === 'netease' && is_array($data) && count($data) < 50) {
        /* 尝试获取完整的 trackIds */
        $detailUrl = 'https://music.163.com/api/v6/playlist/detail?id=' . urlencode($id);
        $ch = curl_init($detailUrl);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'id=' . urlencode($id) . '&n=1000',
            CURLOPT_REFERER        => 'https://music.163.com/',
        ));
        $resp = curl_exec($ch);
        curl_close($ch);
        $detail = json_decode($resp, true);
        $trackIds = isset($detail['playlist']['trackIds']) ? $detail['playlist']['trackIds'] : array();
        $trackCount = isset($detail['playlist']['trackCount']) ? (int)$detail['playlist']['trackCount'] : 0;
        /* 如果 trackIds 数量大于返回的歌曲数量，说明需要补充 */
        if (count($trackIds) > count($data) && $trackCount > 0) {
            /* 批量获取歌曲详情 */
            $allSongs = array();
            $batches = array_chunk($trackIds, 500);
            foreach ($batches as $batch) {
                $ids = array_map(function($t) { return is_array($t) ? $t['id'] : intval($t); }, $batch);
                $ids = array_filter($ids, function($v) { return $v > 0; });
                if (empty($ids)) continue;
                $cParam = '[' . implode(',', array_map(function($id) { return '{"id":' . intval($id) . '}'; }, $ids)) . ']';
                $songUrl = 'https://music.163.com/api/v3/song/detail?c=' . urlencode($cParam);
                $ch2 = curl_init($songUrl);
                curl_setopt_array($ch2, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_CONNECTTIMEOUT => 6,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    CURLOPT_REFERER        => 'https://music.163.com/',
                ));
                $resp2 = curl_exec($ch2);
                curl_close($ch2);
                $songsData = json_decode($resp2, true);
                if (isset($songsData['songs']) && is_array($songsData['songs'])) {
                    foreach ($songsData['songs'] as $song) {
                        if (!is_array($song)) continue;
                        $allSongs[] = array(
                            'id'        => $song['id'] ?? '',
                            'name'      => $song['name'] ?? '',
                            'url_id'    => $song['id'] ?? '',
                            'lyric_id'  => $song['id'] ?? '',
                            'artist'    => implode('/', array_map(function($a) { return $a['name'] ?? ''; }, $song['ar'] ?? array())),
                            'artist_id' => implode('/', array_map(function($a) { return $a['id'] ?? ''; }, $song['ar'] ?? array())),
                            'album'     => $song['al']['name'] ?? '',
                            'album_id'  => $song['al']['id'] ?? '',
                            'pic_id'    => $song['al']['pic'] ?? '',
                            'pic_api'   => '',
                            'url'       => '',
                            'lrc'       => '',
                            'pic'       => '',
                            'source'    => 'netease',
                        );
                    }
                }
            }
            if (count($allSongs) > count($data)) {
                $data = $allSongs;
            }
        }
    }
    if (empty($data)) {
        http_response_code(404);
        echo json_encode(['error' => 'playlist_not_found']);
        exit;
    }
    foreach ($data as &$song) {
        $songId = $song['url_id'] ?? $song['id'] ?? '';
        $lrcId  = $song['lyric_id'] ?? $songId;
        $song['url_id'] = $songId;
        $song['url'] = '';
        /* 直连封面：Meting 的 tencent 歌单带 pic_id（专辑图 ID），可拼出与官方一致的
           y.gtimg.cn 地址，浏览器就能直接从腾讯 CDN 取图，不必为每张封面打一次本站 PHP。
           pic_id 缺失的条目保留空值，前端会退回 pic_api 代理。 */
        $song['pic'] = '';
        if ($server === 'tencent' && !empty($song['pic_id']) && preg_match('/^[A-Za-z0-9]+$/', (string) $song['pic_id'])) {
            $song['pic'] = 'https://y.gtimg.cn/music/photo_new/T002R300x300M000' . $song['pic_id'] . '.jpg?max_age=2592000';
        }
        $song['lrc'] = $localBase . '?server=' . $server . '&type=lrc&id=' . urlencode($lrcId);
        $song['pic_api'] = $localBase . '?server=' . $server . '&type=pic&id=' . urlencode($songId);
    }
    unset($song);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'invalid_request', 'server' => $server, 'type' => $type, 'id' => $id]);
