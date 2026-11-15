<?php
/**
 * TriM3 相册 AJAX 接口
 *
 * POST do=album_list|album_save|album_delete|album_sort
 *        |photo_list|photo_save|photo_delete|photo_sort|cover|upload
 *
 * 独立引导 Typecho 环境（不依赖主题渲染时机）。
 */

use Typecho\Db;
use Typecho\Widget;

require_once dirname(__FILE__, 5) . '/config.inc.php';
require_once dirname(__DIR__) . '/lib/redis-cache.php';
require_once dirname(__DIR__) . '/lib/album.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

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

/* 写主题设置项（options 表 upsert，避免依赖主题表单保存时机） */
function tri_album_set_option(string $name, string $value): void
{
    $triDb = Typecho\Db::get();
    $triRow = $triDb->fetchRow($triDb->select()->from('table.options')->where('name = ?', $name)->where('user = ?', 0));
    if ($triRow) {
        $triDb->query($triDb->update('table.options')->rows(array('value' => $value))->where('name = ?', $name)->where('user = ?', 0));
    } else {
        $triDb->query($triDb->insert('table.options')->rows(array('name' => $name, 'user' => 0, 'value' => $value)));
    }
}

/* 同源校验 */
$ref = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
$refHost = $ref !== '' ? parse_url($ref, PHP_URL_HOST) : '';
if ($refHost !== '' && $refHost !== ($_SERVER['HTTP_HOST'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => '非法来源']);
    exit;
}

$user = Widget::widget('Widget_User');
$isAdmin = $user->hasLogin() && $user->pass('administrator');

/* 只读接口无需登录（供前台选封面等使用），写操作必须管理员 */
$readOnly = in_array($do, ['album_list', 'photo_list'], true);

/* 七牛防盗链适配：
 *  - 读操作返回前对 cover/url/thumb 动态签名（tri3_qiniu_sign_url），保证后台/前台可见
 *  - 写操作入库前剥离 sign/t，防止签名 URL（会过期）被写回存储 */
function tri3_album_sign_list(array $list): array
{
    if (!function_exists('tri3_qiniu_sign_url')) {
        return $list;
    }
    foreach ($list as &$item) {
        if (!empty($item['cover'])) { $item['cover'] = tri3_qiniu_sign_url((string) $item['cover']); }
        if (!empty($item['url']))   { $item['url']   = tri3_qiniu_sign_url((string) $item['url']); }
        if (!empty($item['thumb'])) { $item['thumb'] = tri3_qiniu_sign_url((string) $item['thumb']); }
    }
    unset($item);
    return $list;
}

function tri3_album_strip_sig(string $url): string
{
    $url = trim($url);
    if ($url === '' || strpos($url, '?') === false) {
        return $url;
    }
    $qPos = strpos($url, '?');
    $base = substr($url, 0, $qPos);
    $keep = array();
    foreach (explode('&', (string) substr($url, $qPos + 1)) as $kv) {
        $kv = trim($kv);
        if ($kv === '') {
            continue;
        }
        $k = strtok($kv, '=');
        /* auth_key 是多吉云/七牛的签名（会过期），imageView2 是 CDN 处理参数：
         * 都不该写进库，入库只存裸 URL，渲染时由 tri3_img_proxy_url 重新签名。 */
        if ($k === 'sign' || $k === 't' || $k === 'auth_key' || strpos($kv, 'imageView2') === 0) {
            continue;
        }
        $keep[] = $kv;
    }
    return $base . (!empty($keep) ? '?' . implode('&', $keep) : '');
}

/* 写操作统一 CSRF 防护（Origin 优先，缺失回退 Referer，都缺失拒绝） */
if (!$readOnly && $do !== 'get_settings' && !tri3_csrf_ok()) {
    echo json_encode(['ok' => false, 'msg' => '非法来源']);
    exit;
}

switch ($do) {
    case 'get_settings':
        $triOpt = Widget::widget('Widget_Options');
        $triMode = (string) $triOpt->albumPageMode;
        if ($triMode === '') { $triMode = 'list'; }
        $triAid = (int) ($triOpt->albumPageAid ?: 0);
        $triLay = (string) $triOpt->albumDefaultLayout;
        if ($triLay === '') { $triLay = 'grid'; }
        echo json_encode(array(
            'ok' => true,
            'settings' => array(
                'page_mode' => $triMode, 'default_aid' => $triAid, 'layout' => $triLay,
                'upload_enabled' => true
            ),
            'albums' => tri3_album_sign_list(tri_album_list())
        ));
        exit;

    case 'save_settings':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $triMode = (($_POST['page_mode'] ?? 'list') === 'album') ? 'album' : 'list';
        $triAid = max(0, (int) ($_POST['default_aid'] ?? 0));
        $triLay = (string) ($_POST['layout'] ?? 'grid');
        if (!in_array($triLay, ['grid', 'masonry', 'mosaic', 'list', 'slide'], true)) { $triLay = 'grid'; }
        tri_album_set_option('albumPageMode', $triMode);
        tri_album_set_option('albumPageAid', (string) $triAid);
        tri_album_set_option('albumDefaultLayout', $triLay);
        tri_redis_invalidate(['albums', 'page'], 'album settings updated');
        echo json_encode(['ok' => true]);
        exit;

    case 'album_list':
        echo json_encode(['ok' => true, 'list' => tri3_album_sign_list(tri_album_list())]);
        exit;

    case 'photo_list':
        $aid = (int) ($_REQUEST['aid'] ?? 0);
        echo json_encode(['ok' => true, 'aid' => $aid, 'list' => tri3_album_sign_list(tri_photo_list($aid))]);
        exit;

    case 'album_save':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $aid = tri_album_save(
            (int) ($_POST['aid'] ?? 0),
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['description'] ?? ''),
            tri3_album_strip_sig((string) ($_POST['cover'] ?? ''))
        );
        echo json_encode(['ok' => true, 'aid' => $aid]);
        exit;

    case 'album_delete':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        tri_album_delete((int) ($_POST['aid'] ?? 0));
        echo json_encode(['ok' => true]);
        exit;

    case 'album_sort':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $rawIds = $_POST['ids'] ?? '';
        $ids = array();
        foreach (preg_split('/[,\s]+/', is_array($rawIds) ? implode(',', $rawIds) : (string) $rawIds) as $triI) {
            $triI = trim((string) $triI);
            if ($triI !== '') { $ids[] = (int) $triI; }
        }
        tri_album_sort($ids);
        echo json_encode(['ok' => true]);
        exit;

    case 'photo_link':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $triLAid = (int) ($_POST['aid'] ?? 0);
        $triLUrl = tri3_album_strip_sig(trim((string) ($_POST['url'] ?? '')));
        $triLTitle = trim((string) ($_POST['title'] ?? ''));
        if ($triLAid <= 0) {
            echo json_encode(['ok' => false, 'msg' => '请选择相册']);
            exit;
        }
        if ($triLUrl === '' || !preg_match('#^https?://#i', $triLUrl)) {
            echo json_encode(['ok' => false, 'msg' => '请输入正确的图片地址（http/https 开头）']);
            exit;
        }
        if (strlen($triLUrl) > 1000) {
            echo json_encode(['ok' => false, 'msg' => '图片地址过长']);
            exit;
        }
        $triLPid = tri_photo_save(0, $triLAid, $triLUrl, $triLTitle);
        echo json_encode(['ok' => true, 'pid' => $triLPid]);
        exit;

    case 'photo_save':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $pid = tri_photo_save(
            (int) ($_POST['pid'] ?? 0),
            (int) ($_POST['aid'] ?? 0),
            tri3_album_strip_sig((string) ($_POST['url'] ?? '')),
            (string) ($_POST['title'] ?? '')
        );
        echo json_encode(['ok' => true, 'pid' => $pid]);
        exit;

    case 'photo_delete':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        tri_photo_delete((int) ($_POST['pid'] ?? 0));
        echo json_encode(['ok' => true]);
        exit;

    /* 批量删除相册（ids 逗号分隔，连带相册内图片） */
    case 'album_batch_delete':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $triRawAids = $_POST['ids'] ?? '';
        $triAids = array();
        foreach (preg_split('/[,\s]+/', is_array($triRawAids) ? implode(',', $triRawAids) : (string) $triRawAids) as $triI) {
            $triI = (int) trim((string) $triI);
            if ($triI > 0) { $triAids[] = $triI; }
        }
        if (empty($triAids)) {
            echo json_encode(['ok' => false, 'msg' => '请选择要删除的相册']);
            exit;
        }
        foreach ($triAids as $triI) {
            tri_album_delete($triI);
        }
        echo json_encode(['ok' => true, 'count' => count($triAids)]);
        exit;

    /* 批量删除图片（ids 逗号分隔） */
    case 'photo_batch_delete':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $triRawPids = $_POST['ids'] ?? '';
        $triPids = array();
        foreach (preg_split('/[,\s]+/', is_array($triRawPids) ? implode(',', $triRawPids) : (string) $triRawPids) as $triP) {
            $triP = (int) trim((string) $triP);
            if ($triP > 0) { $triPids[] = $triP; }
        }
        if (empty($triPids)) {
            echo json_encode(['ok' => false, 'msg' => '请选择要删除的图片']);
            exit;
        }
        foreach ($triPids as $triP) {
            tri_photo_delete($triP);
        }
        echo json_encode(['ok' => true, 'count' => count($triPids)]);
        exit;

    case 'photo_sort':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $aid = (int) ($_POST['aid'] ?? 0);
        $rawPids = $_POST['pids'] ?? '';
        $pids = array();
        foreach (preg_split('/[,\s]+/', is_array($rawPids) ? implode(',', $rawPids) : (string) $rawPids) as $triP) {
            $triP = trim((string) $triP);
            if ($triP !== '') { $pids[] = (int) $triP; }
        }
        tri_photo_sort($aid, $pids);
        echo json_encode(['ok' => true]);
        exit;

    case 'album_move':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $aid = (int) ($_POST['aid'] ?? 0);
        $dir = (int) ($_POST['dir'] ?? 1);
        $ids = array();
        foreach (tri_album_list() as $triAX) { $ids[] = (int) $triAX['aid']; }
        $idx = array_search($aid, $ids, true);
        if ($idx === false) { echo json_encode(['ok' => false, 'msg' => '相册不存在']); exit; }
        $swap = $idx + $dir;
        if ($swap >= 0 && $swap < count($ids)) {
            $tmp = $ids[$idx]; $ids[$idx] = $ids[$swap]; $ids[$swap] = $tmp;
            tri_album_sort($ids);
        }
        echo json_encode(['ok' => true]);
        exit;

    case 'photo_move':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $aid = (int) ($_POST['aid'] ?? 0);
        $pid = (int) ($_POST['pid'] ?? 0);
        $dir = (int) ($_POST['dir'] ?? 1);
        $pids = array();
        foreach (tri_photo_list($aid) as $triPX) { $pids[] = (int) $triPX['pid']; }
        $idx = array_search($pid, $pids, true);
        if ($idx === false) { echo json_encode(['ok' => false, 'msg' => '图片不存在']); exit; }
        $swap = $idx + $dir;
        if ($swap >= 0 && $swap < count($pids)) {
            $tmp = $pids[$idx]; $pids[$idx] = $pids[$swap]; $pids[$swap] = $tmp;
            tri_photo_sort($aid, $pids);
        }
        echo json_encode(['ok' => true]);
        exit;

    case 'cover':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        tri_album_set_cover((int) ($_POST['aid'] ?? 0), tri3_album_strip_sig((string) ($_POST['cover'] ?? '')));
        echo json_encode(['ok' => true]);
        exit;

    /* 存储方式信息：本地 or 图床（类型/地址），供相册管理页展示上传去向 */
    case 'storage':
        $triOpt = Widget::widget('Widget_Options');
        $triType = trim((string) $triOpt->albumApiType);
        if ($triType === 'qiniu') {
            /* 七牛云：不需要 API 地址，按专属配置完整性判断 */
            $qOk = trim((string) $triOpt->qiniuAccessKey) !== '' && trim((string) $triOpt->qiniuSecretKey) !== '' && trim((string) $triOpt->qiniuBucket) !== '' && trim((string) $triOpt->qiniuDomain) !== '';
            echo json_encode(array(
                'ok' => $qOk,
                'storage' => 'qiniu',
                'label' => $qOk ? '七牛云图床' : '七牛云（配置不完整）',
                'type' => 'qiniu',
                'url' => trim((string) $triOpt->qiniuDomain),
            ));
            exit;
        }
        $triApiUrl = trim((string) $triOpt->albumApiUrl);
        if ($triApiUrl === '') {
            echo json_encode(array(
                'ok' => true,
                'storage' => 'local',
                'label' => '本机存储',
                'type' => '',
                'url' => '',
            ));
            exit;
        }
        if ($triType === '') { $triType = 'lsky'; }
        $triLabel = $triType === 'chevereto' ? 'Chevereto 图床' : ($triType === 'lsky' ? '兰空图床' : '自建图床 API');
        echo json_encode(array(
            'ok' => true,
            'storage' => $triType,
            'label' => $triLabel,
            'type' => $triType,
            'url' => $triApiUrl,
        ));
        exit;

    case 'upload':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        /* 兼容单文件（file）与多文件（file[]）两种提交形态 */
        $triFiles = array();
        if (isset($_FILES['file']['name']) && is_array($_FILES['file']['name'])) {
            $triN = count($_FILES['file']['name']);
            for ($triI = 0; $triI < $triN; $triI++) {
                if (($_FILES['file']['error'][$triI] ?? 1) === UPLOAD_ERR_OK) {
                    $triFiles[] = array(
                        'name' => (string) $_FILES['file']['name'][$triI],
                        'type' => (string) ($_FILES['file']['type'][$triI] ?? ''),
                        'tmp_name' => (string) $_FILES['file']['tmp_name'][$triI],
                        'error' => (int) $_FILES['file']['error'][$triI],
                        'size' => (int) ($_FILES['file']['size'][$triI] ?? 0),
                    );
                }
            }
        } elseif (isset($_FILES['file']['tmp_name']) && is_string($_FILES['file']['tmp_name'])) {
            $triFiles[] = $_FILES['file'];
        }
        if (empty($triFiles)) {
            echo json_encode(['ok' => false, 'msg' => '未收到文件', 'failed' => []]);
            exit;
        }
        $triAid = (int) ($_POST['aid'] ?? 0);
        $triOk = 0;
        $triFailed = array();
        $triUrls = array();
        $triStorage = 'local';
        foreach ($triFiles as $triF) {
            $triR = tri_album_upload($triF);
            if ($triR['ok']) {
                $triUrls[] = (string) ($triR['url'] ?? '');
                if ($triAid > 0) {
                    tri_photo_save(0, $triAid, (string) $triR['url'], pathinfo((string) $triF['name'], PATHINFO_FILENAME));
                }
                /* 取当前存储方式（首个成功文件即知去向） */
                $triOpt = Widget::widget('Widget_Options');
                $triApiUrl = trim((string) $triOpt->albumApiUrl);
                $triStorage = $triApiUrl === '' ? 'local' : trim((string) $triOpt->albumApiType);
                if ($triStorage === '' && trim((string) $triOpt->albumApiType) === 'qiniu') {
                    $triStorage = 'qiniu';
                }
                if ($triStorage === '') { $triStorage = 'lsky'; }
                $triOk++;
            } else {
                $triFailed[] = array('name' => (string) ($triF['name'] ?? '未知文件'), 'msg' => (string) ($triR['msg'] ?? '上传失败'));
            }
        }
        $triSignedUrls = array();
        foreach ($triUrls as $triU) {
            $triSignedUrls[] = function_exists('tri3_qiniu_sign_url') ? tri3_qiniu_sign_url((string) $triU) : $triU;
        }
        echo json_encode(array(
            'ok' => true,
            'count' => $triOk,
            'failed' => $triFailed,
            'storage' => $triStorage,
            'urls' => $triSignedUrls,
        ));
        exit;

    default:
        echo json_encode(['ok' => false, 'msg' => '未知操作']);
        exit;
}
