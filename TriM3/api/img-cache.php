<?php
/* TriM3 · 图片缓存管理
 *
 * 作用：将全站内容图片（文章/单页正文、说说、相册、小组件图片）通过
 *       api/img.php 代理「预生成」为 WebP/JPEG 磁盘缓存，
 *       前台访问全部命中缓存，不再实时转换占用 PHP 资源。
 *
 * 接口（均需管理员登录，或携带 ?secret=后台设置中的密钥）：
 *   do=scan            扫描并返回全部图片来源 URL 列表（JSON）
 *   do=gen&offset=&n=  生成第 offset 批，每批 n 张（AJAX 分批轮询用）
 *   do=run&secret=     全量生成（set_time_limit(0)，宝塔计划任务用）
 *   do=stats           缓存统计（文件数/大小/上次生成时间/总数）
 *   do=clear           清空图片缓存
 *
 * 宝塔计划任务（每天一次，示例）：
 *   curl -s "https://example.com/usr/themes/TriM3/api/img-cache.php?do=run&secret=你的密钥" >/dev/null 2>&1
 */
use Typecho\Widget;
use Typecho\Db;

require_once dirname(__FILE__, 5) . '/config.inc.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/img-pipeline.php';

/* 独立入口下固定 rootUrl，保证后台登录态 Cookie 可读 */
if (!defined('__TYPECHO_ROOT_URL__')) {
    $triScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('__TYPECHO_ROOT_URL__', $triScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

\Widget\Init::alloc();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
ini_set('display_errors', '0');
error_reporting(0);

/* 权限：管理员登录 或 密钥 */
$triCfgSecret = trim((string) (Helper::options()->imgCacheSecret ?? ''));
$triGot = trim((string) ($_GET['secret'] ?? ''));
$triIsAdmin = false;
try {
    $triU = Widget::widget('Widget_User');
    $triIsAdmin = $triU->hasLogin() && $triU->pass('administrator');
} catch (Throwable $e) {
    $triIsAdmin = false;
}
$triIsSecret = ($triCfgSecret !== '' && hash_equals($triCfgSecret, $triGot));
$triAuthed = $triIsAdmin || $triIsSecret;

if (!$triAuthed) {
    exit(json_encode(['ok' => false, 'msg' => '无权限']));
}

$triDo = (string) ($_REQUEST['do'] ?? 'stats');
/* 设置写入只允许已登录管理员：密钥是给计划任务做预生成用的，
   不应因为密钥出现在 URL 里就能改动站点配置。 */
if ($triDo === 'save_settings' && !$triIsAdmin) {
    exit(json_encode(['ok' => false, 'msg' => '需要管理员登录']));
}
$triCacheDir = dirname(__DIR__) . '/assets/cache/img/';
$triImgApi = rtrim((string) Helper::options()->themeUrl, '/') . '/api/img.php';

/* ---------- 扫描全部图片来源 ---------- */
function tri_imgcache_scan(): array
{
    $triUrls = [];
    try {
        $db = Db::get();

        /* 1) 文章/单页/说说正文中的 <img src> 与 [img] 短代码（text 列，Typecho 无 content 列） */
        $rows = $db->fetchAll($db->select()->from('table.contents')
            ->where('type IN ?', ['post', 'page', 'moment']));
        foreach ($rows as $r) {
            $triBody = (string) ($r['text'] ?? $r['content'] ?? '');
            if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $triBody, $m1)) { $triUrls = array_merge($triUrls, $m1[1]); }
            if (preg_match_all('/\[img\]([^\[\]]+)\[\/img\]/i', $triBody, $m2)) { $triUrls = array_merge($triUrls, $m2[1]); }
        }

        /* 2) 说说图集 / 相册 / 音乐封面 等 fields JSON */
        $fs = $db->fetchAll($db->select()->from('table.fields')
            ->where('name IN ?', ['moment_ga', 'moment_music', 'moment_video', 'moment_live']));
        foreach ($fs as $f) {
            $triJson = (string) $f['str_value'];
            if ($triJson === '' || ($triJson[0] !== '[' && $triJson[0] !== '{')) { continue; }
            if (preg_match_all('/"(?:src|thumb|pic|img|cover|url)"\s*:\s*"([^"]+)"/', $triJson, $m3)) {
                $triUrls = array_merge($triUrls, $m3[1]);
            }
        }

        /* 3) 小组件图片（单张图片组件 URL） */
        foreach (['widgetsLeft', 'widgetsRight'] as $triKey) {
            $triW = (string) (Helper::options()->{$triKey} ?? '');
            if ($triW === '') { continue; }
            $triWj = json_decode($triW, true);
            if (is_array($triWj)) {
                foreach ($triWj as $triWit) {
                    if (isset($triWit['type']) && $triWit['type'] === 'image' && !empty($triWit['url'])) {
                        $triUrls[] = (string) $triWit['url'];
                    }
                }
            }
        }
    } catch (Throwable $e) {
    }

    /* 白名单 + 去重 */
    /* 白名单：清单由主题依据 siteUrl + 图床域名生成（assets/cache/img-hosts.php），
     * 与 api/img.php 共用同一份数据源 → 换域名安装后自动生效。
     * '.' 前缀条目匹配其任意子域。 */
    $triAllow = [];
    $triConfFile = dirname(__DIR__) . '/assets/cache/img-hosts.php';
    if (is_file($triConfFile)) {
        $triConfData = @include $triConfFile;
        if (is_array($triConfData) && isset($triConfData['hosts']) && is_array($triConfData['hosts'])) {
            $triAllow = array_values(array_unique(array_filter($triConfData['hosts'])));
        }
    }
    $triAllowOk = function ($h) use ($triAllow) {
        foreach ($triAllow as $a) {
            if ($a === $h) { return true; }
            if ($a !== '' && $a[0] === '.' && substr($h, -strlen($a)) === $a) { return true; }
        }
        return false;
    };
    $triOut = [];
    foreach ($triUrls as $triU) {
        $triU = trim((string) $triU);
        if ($triU === '' || strpos($triU, 'data:') === 0 || strpos($triU, 'img.php') !== false) { continue; }
        $triH = @parse_url($triU, PHP_URL_HOST);
        if ($triH === false || $triH === null || !$triAllowOk(strtolower($triH))) { continue; }
        if (!preg_match('/\.(jpe?g|png|webp|gif|bmp)(\?|$)/i', $triU)) { continue; }
        $triOut[$triU] = true;
    }
    return array_keys($triOut);
}

/* ---------- 预生成：一次取图，产出全部档位 ----------
 * 旧实现给每档发一次自 HTTP 请求（3 档 = 原图下载 3 次 + 3 次解码重编码），
 * 且档位（400/600/800）与前台真正索取的档位（128/320/400/600/800/1200）不一致，
 * 生成出来的文件前台命不中 —— 等于白做。这里改为进程内：
 *   1) 原图只下载一次、只解码一次；
 *   2) 逐档写出与 api/img.php 完全一致的缓存文件（同一套键与编码）；
 *   3) 已存在的档位直接跳过（增量，适合每天跑）；
 *   4) 只预热「前台确实会走代理」的图 —— 走 CDN 直出或静态直出的图跳过。
 */
function tri_imgcache_needs_proxy(string $src): bool
{
    if (!function_exists('tri3_img_proxy_url')) { return true; }
    $u = tri3_img_proxy_url($src, 320);
    return (strpos($u, '/api/img.php') !== false);
}

function tri_imgcache_gen_one(string $src): array
{
    $cacheDir = dirname(__DIR__) . '/assets/cache/img/';
    if (!is_file(dirname(__DIR__) . '/lib/img-pipeline.php')) {
        return array('state' => 'skip', 'generated' => 0, 'skipped' => 0, 'failed' => 0);
    }
    require_once dirname(__DIR__) . '/lib/img-pipeline.php';
    if (!tri_imgcache_needs_proxy($src)) {
        return array('state' => 'passthrough', 'generated' => 0, 'skipped' => 0, 'failed' => 0);
    }
    $widths = tri3_img_pipeline_widths();
    /* v1.2.85：预生成的宽度/质量/格式必须与前台实际请求完全一致。
     * 旧版这里写死 webp + q=70，而前台「默认质量」改成 80 后，预生成的文件
     * 前台永远不会请求（缓存命中率 0），等于白做。 */
    $triGenQ = max(30, min(95, (int) (Helper::options()->imgDefaultQ ?? 80)));
    $triGenFmt = ((string) (Helper::options()->imgWebpEnable ?? '1') === '1') ? 'webp' : 'jpeg';
    /* 全部档位都已存在时连原图都不必下载 */
    $need = 0;
    foreach ($widths as $w) {
        if (!is_file(tri3_img_cache_file($cacheDir, $src, $w, false, $triGenFmt, $triGenQ))) { $need++; }
    }
    if ($need === 0) {
        return array('state' => 'warm', 'generated' => 0, 'skipped' => count($widths), 'failed' => 0);
    }
    $data = tri3_img_fetch_origin($src, $cacheDir);
    if ($data === '') {
        return array('state' => 'fetch-failed', 'generated' => 0, 'skipped' => 0, 'failed' => 0);
    }
    $r = tri3_img_warm_variants($data, $src, $cacheDir, $widths, $triGenFmt, $triGenQ);
    return array('state' => 'ok', 'generated' => $r['generated'], 'skipped' => $r['skipped'], 'failed' => $r['failed']);
}

/* ---------- 统计 ---------- */
function tri_imgcache_stats(string $dir): array
{
    $triN = 0; $triBytes = 0; $triNew = 0;
    $triFiles = is_dir($dir) ? @glob($dir . '*') : [];
    if (is_array($triFiles)) {
        foreach ($triFiles as $triF) {
            if (is_file($triF)) {
                $triN++;
                $triBytes += @filesize($triF);
                if (@filemtime($triF) > $triNew) { $triNew = (int) @filemtime($triF); }
            }
        }
    }
    return ['files' => $triN, 'bytes' => $triBytes, 'last' => $triNew];
}

    /* CSRF 只约束「凭登录态 Cookie」的浏览器请求；凭密钥的服务端调用（计划任务/自动预热）无 Cookie，无从校验，也不应要求 */
if (!$triIsSecret && (!function_exists('tri3_csrf_ok') || !tri3_csrf_ok())) { http_response_code(403); exit('CSRF'); }
switch ($triDo) {
case 'save_settings':
        /* 面板内独立保存：把 imgCache* 字段写回 theme:TriM3 JSON（不依赖 Typecho form 注册） */
        $triThName = (string) Helper::options()->theme;
        $triDbS = Db::get();
        $triRowS = $triDbS->fetchRow($triDbS->select()->from('table.options')->where('name = ?', 'theme:' . $triThName));
        $triCfgS = ($triRowS && isset($triRowS['value'])) ? json_decode((string) $triRowS['value'], true) : array();
        if (!is_array($triCfgS)) { $triCfgS = array(); }
        $triCfgS['imgCacheSecret'] = trim((string) ($_POST['imgCacheSecret'] ?? ''));
        $triCfgS['imgCacheAuto'] = (string) ($_POST['imgCacheAuto'] ?? '0');
        $triCfgS['imgCacheInterval'] = trim((string) ($_POST['imgCacheInterval'] ?? '1'));
        $triCfgS['imgProxyEnable'] = (string) ($_POST['imgProxyEnable'] ?? '1');
        $triCfgS['imgWebpEnable'] = (string) ($_POST['imgWebpEnable'] ?? '1');
        $triCfgS['imgDefaultW'] = (string) max(32, min(2000, (int) ($_POST['imgDefaultW'] ?? 800)));
        $triCfgS['imgDefaultQ'] = (string) max(30, min(95, (int) ($_POST['imgDefaultQ'] ?? 80)));
        $triCfgS['imgCacheMax'] = (string) max(100, min(5000, (int) ($_POST['imgCacheMax'] ?? 800)));
        $triJsonS = json_encode($triCfgS, JSON_UNESCAPED_UNICODE);
        $triChkS = $triDbS->fetchRow($triDbS->select()->from('table.options')->where('name = ?', 'theme:' . $triThName));
        if ($triChkS) {
            $triDbS->query($triDbS->update('table.options')->rows(array('value' => $triJsonS))->where('name = ?', 'theme:' . $triThName));
        } else {
            $triDbS->query($triDbS->insert('table.options')->rows(array('name' => 'theme:' . $triThName, 'user' => 0, 'value' => $triJsonS)));
        }
        echo json_encode(['ok' => true]);
        break;

    case 'scan':
        $triList = tri_imgcache_scan();
        echo json_encode(['ok' => true, 'total' => count($triList), 'list' => array_slice($triList, 0, 50)]);
        break;

    case 'gen':
        $triList = tri_imgcache_scan();
        $triOffset = max(0, (int) ($_GET['offset'] ?? 0));
        $triN = max(1, min(20, (int) ($_GET['n'] ?? 10)));
        $triSlice = array_slice($triList, $triOffset, $triN);
        $triSum = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'passthrough' => 0, 'warm' => 0];
        foreach ($triSlice as $triSrc) {
            $triR = tri_imgcache_gen_one($triSrc);
            if ($triR['state'] === 'passthrough') { $triSum['passthrough']++; continue; }
            if ($triR['state'] === 'warm') { $triSum['warm']++; continue; }
            $triSum['generated'] += $triR['generated'];
            $triSum['skipped'] += $triR['skipped'];
            $triSum['failed'] += $triR['failed'];
        }
        $triDone = $triOffset + count($triSlice);
        echo json_encode(['ok' => true, 'done' => $triDone, 'total' => count($triList), 'batch' => $triSum,
            'finished' => $triDone >= count($triList)]);
        break;

    case 'run':
        @set_time_limit(0);
        $triList = tri_imgcache_scan();
        $triT0 = time();
        $triBudget = max(30, min(1800, (int) ($_GET['budget'] ?? 600)));   /* 默认最多跑 10 分钟 */
        $triOK = 0; $triSkip = 0; $triFail = 0; $triPass = 0; $triWarm = 0;
        foreach ($triList as $triSrc) {
            if (time() - $triT0 > $triBudget) { break; }
            $triR = tri_imgcache_gen_one($triSrc);
            if ($triR['state'] === 'passthrough') { $triPass++; continue; }
            if ($triR['state'] === 'warm') { $triWarm++; continue; }
            if ($triR['state'] === 'fetch-failed') { $triFail++; continue; }
            $triOK += $triR['generated'];
            $triSkip += $triR['skipped'];
            $triFail += $triR['failed'];
        }
        echo json_encode(['ok' => true, 'generated' => $triOK, 'skipped' => $triSkip, 'failed' => $triFail,
            'already_warm' => $triWarm, 'passthrough' => $triPass, 'total' => count($triList),
            'seconds' => time() - $triT0, 'budget' => $triBudget]);
        break;

    case 'stats':
        $triS = tri_imgcache_stats($triCacheDir);
        $triTotal = count(tri_imgcache_scan());
        $triStale = $triS['last'] ? (int) floor((time() - $triS['last']) / 86400) : 999;
        $triCover = 0; $triNeed = 0;
        if (is_file(dirname(__DIR__) . '/lib/img-pipeline.php')) {
            require_once dirname(__DIR__) . '/lib/img-pipeline.php';
            foreach (array_slice(tri_imgcache_scan(), 0, 200) as $triOne) {
                if (!tri_imgcache_needs_proxy($triOne)) { continue; }
                $triNeed++;
                foreach (tri3_img_pipeline_widths() as $triW) {
                    /* 与生成侧保持一致：格式/质量取后台设置（此前写死 webp/70，改设置后覆盖率恒为 0） */
                    $triFmt = (string) tri_opt('imgWebpEnable', '1') === '1' ? 'webp' : 'jpg';
                    $triQ   = max(30, min(95, (int) tri_opt('imgDefaultQ', '80')));
                    if (is_file(tri3_img_cache_file($triCacheDir, $triOne, $triW, false, $triFmt, $triQ))) { $triCover++; break; }
                }
            }
        }
        echo json_encode(['ok' => true, 'cover' => $triCover, 'need' => $triNeed, 'files' => $triS['files'], 'bytes' => $triS['bytes'],
            'last' => $triS['last'] ? date('Y-m-d H:i', $triS['last']) : '从未生成', 'total' => $triTotal,
            'stale' => $triStale]);
        break;

case 'clear':
        /* CSRF 只约束「凭登录态 Cookie」的浏览器请求；凭密钥的服务端调用无 Cookie，不校验 */
        if (!$triIsSecret && !tri3_csrf_ok()) {
            http_response_code(403);
            exit('CSRF');
        }
        $triFiles = is_dir($triCacheDir) ? @glob($triCacheDir . '*') : [];
        $triCnt = 0;
        if (is_array($triFiles)) {
            foreach ($triFiles as $triF) { if (is_file($triF) && @unlink($triF)) { $triCnt++; } }
        }
        echo json_encode(['ok' => true, 'cleared' => $triCnt]);
        break;

    default:
        echo json_encode(['ok' => false, 'msg' => '未知操作']);
}
