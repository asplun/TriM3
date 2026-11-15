<?php
/**
 * TriM3 小组件 AJAX 接口
 *
 * POST do=widgets_get | widgets_save | view_add | album_list
 *
 * 独立引导 Typecho 环境（不依赖主题渲染时机）。
 */

use Typecho\Widget;
use Typecho\Db;

require_once dirname(__FILE__, 5) . '/config.inc.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/redis-cache.php';
require_once dirname(__DIR__) . '/lib/widgets.php';

/* 独立入口下固定 rootUrl，保证后台登录态 Cookie 可读 */
if (!defined('__TYPECHO_ROOT_URL__')) {
    $triScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('__TYPECHO_ROOT_URL__', $triScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

\Widget\Init::alloc();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$do = isset($_REQUEST['do']) ? (string) $_REQUEST['do'] : '';

/* 写组件配置：存独立 options 行（theme:TriM3 JSON 行会被「外观设置」整行覆盖，组件配置必须独立） */
function tri_widgets_set_option(string $key, string $value): void
{
    $triDb = Db::get();
    $triRow = $triDb->fetchRow($triDb->select()->from('table.options')->where('name = ?', $key)->where('user = ?', 0));
    if ($triRow) {
        $triDb->query($triDb->update('table.options')->rows(array('value' => $value))->where('name = ?', $key)->where('user = ?', 0));
    } else {
        $triDb->query($triDb->insert('table.options')->rows(array('name' => $key, 'user' => 0, 'value' => $value)));
    }
    /* 清理 theme:TriM3 JSON 行里的旧键，防止歧义 */
    try {
        $triJRow = $triDb->fetchRow($triDb->select()->from('table.options')->where('name = ?', 'theme:TriM3')->where('user = ?', 0));
        if ($triJRow && !empty($triJRow['value'])) {
            $triJ = json_decode((string) $triJRow['value'], true);
            if (is_array($triJ) && array_key_exists($key, $triJ)) {
                unset($triJ[$key]);
                $triDb->query($triDb->update('table.options')->rows(array('value' => json_encode($triJ, JSON_UNESCAPED_UNICODE)))
                    ->where('name = ?', 'theme:TriM3')->where('user = ?', 0));
            }
        }
    } catch (Throwable $e) { /* ignore */ }
}

/* 同源校验 */
$ref = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
$refHost = $ref !== '' ? parse_url($ref, PHP_URL_HOST) : '';
if ($refHost !== '' && $refHost !== ($_SERVER['HTTP_HOST'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => '非法来源']);
    exit;
}

/* 写操作统一 CSRF 防护（Origin 优先，缺失回退 Referer，都缺失拒绝） */
$triWriteOps = array('widgets_save', 'view_add');
if (in_array($do, $triWriteOps, true) && !tri3_csrf_ok()) {
    echo json_encode(['ok' => false, 'msg' => '非法来源']);
    exit;
}

$user = Widget::widget('Widget_User');
$isAdmin = $user->hasLogin() && $user->pass('administrator');

switch ($do) {
    /* 读取配置 + 组件定义（后台管理页用） */
    case 'widgets_get':
        if (!$isAdmin) { echo json_encode(['ok' => false, 'msg' => '需要管理员登录']); exit; }
        echo json_encode(array(
            'ok' => true,
            'defs' => tri_widgets_defs(),
            'config' => tri_widgets_get(),
            'albums' => tri_widgets_albums_list(),
        ), JSON_UNESCAPED_UNICODE);
        exit;

    /* 保存配置 */
    case 'widgets_save':
        if (!$isAdmin) { echo json_encode(['ok' => false, 'msg' => '需要管理员登录']); exit; }
        $left = isset($_POST['left']) ? json_decode((string) $_POST['left'], true) : null;
        $right = isset($_POST['right']) ? json_decode((string) $_POST['right'], true) : null;
        if (!is_array($left) || !is_array($right)) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        /* 清洗：只保留注册组件 + 标量设置 */
        $defs = tri_widgets_defs();
        $sides = array('left' => $left, 'right' => $right);
        foreach ($sides as $side => $list) {
            $out = array();
            foreach ($list as $w) {
                if (!is_array($w) || !isset($w['type']) || !isset($defs[$w['type']])) { continue; }
                $settings = array();
                if (isset($w['settings']) && is_array($w['settings'])) {
                    foreach ($w['settings'] as $k => $v) {
                        if (is_string($v) || is_int($v) || is_float($v)) {
                            $settings[$k] = (string) $v;
                        }
                    }
                }
                /* 空设置必须以 {} 存储（PHP 空数组 json_encode 为 []，前端会误当数组丢掉值） */
                if (count($settings) === 0) { $settings = new stdClass(); }
                $out[] = array(
                    'id' => isset($w['id']) && is_string($w['id']) && $w['id'] !== '' ? $w['id'] : uniqid('w'),
                    'type' => $w['type'],
                    'settings' => $settings,
                );
            }
            $sides[$side] = $out;
        }
        $left = $sides['left'];
        $right = $sides['right'];
        tri_widgets_set_option('widgetsLeft', json_encode($left, JSON_UNESCAPED_UNICODE));
        tri_widgets_set_option('widgetsRight', json_encode($right, JSON_UNESCAPED_UNICODE));
        tri_redis_invalidate(['page'], 'widgets updated');
        echo json_encode(['ok' => true, 'msg' => '已保存']);
        exit;

    /* 只读访客数（通知面板使用，不重复计数） */
    case 'view_read':
        $triV = tri_widgets_views();
        echo json_encode(array('ok' => true, 'today' => $triV['today'], 'total' => $triV['total']));
        exit;

    /* 访客 +1（前台异步，无需登录） */
    case 'view_add':
        tri_widgets_views_add();
        $triV = tri_widgets_views();
        echo json_encode(array('ok' => true, 'today' => $triV['today'], 'total' => $triV['total']));
        exit;

    /* 归档：某月文章列表（懒加载，无需登录） */
    case 'archive_month':
        $triYm = isset($_POST['ym']) ? trim((string) $_POST['ym']) : '';
        if (!preg_match('/^(\d{4})-(\d{2})$/', $triYm, $triM)) {
            echo json_encode(array('ok' => false, 'msg' => '参数错误'));
            exit;
        }
        $triStart = strtotime($triM[1] . '-' . $triM[2] . '-01');
        $triEnd = strtotime('+1 month', $triStart);
        $triRows = array();
        $triDb = Typecho_Db::get();
        $triPosts = $triDb->fetchAll($triDb->select()->from('table.contents')
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->where('created >= ?', $triStart)
            ->where('created < ?', $triEnd)
            ->order('created', Typecho_Db::SORT_DESC));
        $triRoute = Typecho_Router::get('post');
        foreach ($triPosts as $triP) {
            $triSlug = (string) ($triP['slug'] ?? '');
            $triPerma = '';
            try {
                /* 路由需要 category 参数，查该文章首个分类 */
                $triCat = '';
                $triRel = $triDb->fetchRow($triDb->select()->from('table.relationships')->where('cid = ?', (int) $triP['cid'])->limit(1));
                if ($triRel) {
                    $triMeta = $triDb->fetchRow($triDb->select()->from('table.metas')->where('mid = ?', (int) ($triRel['mid'] ?? 0))->limit(1));
                    if ($triMeta) { $triCat = (string) ($triMeta['slug'] ?? ''); }
                }
                $triPerma = Typecho_Router::url('post', array(
                    'cid' => (int) $triP['cid'],
                    'created' => (int) $triP['created'],
                    'slug' => $triSlug !== '' ? $triSlug : (int) $triP['cid'],
                    'category' => $triCat,
                ), (string) Helper::options()->index);
            } catch (Throwable $e) {
                $triPerma = '';
            }
            $triRows[] = array(
                'title' => (string) ($triP['title'] ?? ''),
                'permalink' => $triPerma,
                'd' => date('m-d', (int) $triP['created']),
            );
        }
        echo json_encode(array('ok' => true, 'rows' => $triRows), JSON_UNESCAPED_UNICODE);
        exit;

    /* 今日诗词（服务端代理：v2.jinrishici.com 对浏览器 CORS 预检不友好） */
    case 'poem':
        $triRedisPoem = tri_redis_get('poem', 'widgets');
        if (is_array($triRedisPoem) && !empty($triRedisPoem['ok']) && !empty($triRedisPoem['content'])) {
            echo json_encode($triRedisPoem, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $triCache = dirname(__DIR__) . '/cache/poem.json';
        if (is_file($triCache) && (time() - filemtime($triCache)) < 600) {
            $triC = json_decode((string) @file_get_contents($triCache), true);
            if (is_array($triC) && !empty($triC['ok']) && !empty($triC['content'])) {
                tri_redis_set('poem', $triC, 'widgets', 600);
                echo json_encode($triC, JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        $triGet = function (string $u, string $triHdr = '') {
            $triCtx = stream_context_create(array(
                'http' => array(
                    'timeout' => 10,
                    'ignore_errors' => true,
                    'header' => "User-Agent: TriM3/1.0\r\nAccept: application/json\r\n" . ($triHdr !== '' ? $triHdr . "\r\n" : ''),
                ),
                'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
            ));
            $triB = @file_get_contents($u, false, $triCtx);
            return $triB === false ? '' : $triB;
        };
        $triToken = json_decode($triGet('https://v2.jinrishici.com/token'), true);
        $triTok = isset($triToken['data']) && is_string($triToken['data']) ? $triToken['data'] : '';
        $triOut = array('ok' => false);
        if ($triTok !== '') {
            $triS = json_decode($triGet('https://v2.jinrishici.com/sentence', 'X-User-Token: ' . $triTok), true);
            if (is_array($triS) && isset($triS['data']['content'])) {
                $triD = $triS['data'];
                $triOut = array(
                    'ok' => true,
                    'content' => (string) $triD['content'],
                    'origin' => isset($triD['origin']['title']) ? (string) $triD['origin']['title'] : '',
                    'author' => isset($triD['origin']['author']) ? (string) $triD['origin']['author'] : '',
                );
                tri_redis_set('poem', $triOut, 'widgets', 600);
                @file_put_contents($triCache, json_encode($triOut, JSON_UNESCAPED_UNICODE));
            }
        }
        echo json_encode($triOut, JSON_UNESCAPED_UNICODE);
        exit;

    default:
        echo json_encode(['ok' => false, 'msg' => '未知操作']);
        exit;
}

/* 相册列表（后台下拉选项） */
function tri_widgets_albums_list(): array
{
    if (!function_exists('tri_album_list')) {
        require_once dirname(__DIR__) . '/lib/album.php';
    }
    if (!function_exists('tri_album_list')) { return array(); }
    try {
        $triAlbums = tri_album_list();
        $triOut = array();
        foreach ($triAlbums as $triA) {
            $triOut[] = array('aid' => (int) ($triA['aid'] ?? 0), 'name' => (string) ($triA['name'] ?? ''));
        }
        return $triOut;
    } catch (Throwable $e) {
        return array();
    }
}
