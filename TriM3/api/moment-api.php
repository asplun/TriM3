<?php
/**
 * TriM3 说说 AJAX 接口
 *
 * POST do=add|del|like|sticky|list
 *   add   发布说说（管理员）  content / images(每行一个URL) / private
 *   del   删除说说（管理员）  cid
 *   like  点赞               cid
 *   sticky 置顶/取消置顶     cid
 *   list  分页列表           page
 *
 * 独立引导 Typecho 环境（不依赖主题渲染时机）。
 */

use Typecho\Db;
use Typecho\Widget;

require_once dirname(__FILE__, 5) . '/config.inc.php';
require_once dirname(__DIR__) . '/lib/redis-cache.php';
require_once dirname(__DIR__) . '/lib/moment.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

/* 独立入口下 getRequestRoot() 会返回带路径的请求地址（/usr/themes/TriM3），
   导致 Cookie 前缀 md5(rootUrl) 与后台登录时不一致，User 登录态读不到。
   显式固定为站点根（无斜杠），与后台 /admin 下计算出的 rootUrl 保持一致。 */
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

/* 同源校验（防跨站提交） */
$ref = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
$refHost = $ref !== '' ? parse_url($ref, PHP_URL_HOST) : '';
if ($refHost !== '' && $refHost !== ($_SERVER['HTTP_HOST'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => '非法来源']);
    exit;
}

/* 写操作统一 CSRF 防护（Origin 优先，缺失回退 Referer，都缺失拒绝） */
$triWriteOps = array('add', 'edit', 'del', 'batch_del', 'like', 'sticky', 'cat_save', 'cat_del', 'cat_order', 'view');
if (in_array($do, $triWriteOps, true) && !tri3_csrf_ok()) {
    echo json_encode(['ok' => false, 'msg' => '非法来源']);
    exit;
}

$user = Widget::widget('Widget_User');
$isAdmin = $user->hasLogin() && $user->pass('administrator');

switch ($do) {
    case 'add':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无发布权限']);
            exit;
        }
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '') {
            echo json_encode(['ok' => false, 'msg' => '内容不能为空']);
            exit;
        }
        if (mb_strlen($content) > 2000) {
            echo json_encode(['ok' => false, 'msg' => '内容过长（最多 2000 字）']);
            exit;
        }
        /* 图文混排优先：正文已含某媒体标记时，忽略独立媒体字段，避免重复显示 */
        $hasTagImg   = strpos($content, '[img]') !== false;
        $hasTagMusic = strpos($content, '[music]') !== false;
        $hasTagVideo = strpos($content, '[video]') !== false;
        $hasTagLive  = strpos($content, '[live]') !== false;
        $hasTagBili  = strpos($content, '[bili]') !== false;
        $images = [];
        $rawImgs = $hasTagImg ? '' : (string) ($_POST['images'] ?? '');
        foreach (preg_split('/\r\n|\r|\n/', $rawImgs) as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('#^https?://#i', $line)) {
                $images[] = ['src' => $line, 'thumb' => $line];
            }
        }
        if (count($images) > 9) {
            echo json_encode(['ok' => false, 'msg' => '最多 9 张图']);
            exit;
        }
        $private = !empty($_POST['private']);
        $cat = trim((string) ($_POST['cat'] ?? ''));

        /* 音乐卡片：{server,id,title,artist,pic} —— server 必填，id 必填 */
        $music = null;
        $mSrv = $hasTagMusic ? '' : trim((string) ($_POST['music_server'] ?? ''));
        $mId = $hasTagMusic ? '' : trim((string) ($_POST['music_id'] ?? ''));
        if ($mSrv !== '' && $mId !== '') {
            $music = [
                'server' => $mSrv === 'qq' ? 'tencent' : ($mSrv === 'local' ? 'local' : 'netease'),
                'id'     => $mId,
                'title'  => trim((string) ($_POST['music_title'] ?? '')),
                'artist' => trim((string) ($_POST['music_artist'] ?? '')),
                'pic'    => trim((string) ($_POST['music_pic'] ?? '')),
            ];
        }

        /* 视频：{src,poster} */
        $video = null;
        $vSrc = $hasTagVideo ? '' : trim((string) ($_POST['video_src'] ?? ''));
        if ($vSrc !== '') {
            $video = [
                'src'    => $vSrc,
                'poster' => trim((string) ($_POST['video_poster'] ?? '')),
            ];
        }

        /* 实况图：{img,video} */
        $live = null;
        $lvImg = $hasTagLive ? '' : trim((string) ($_POST['live_img'] ?? ''));
        $lvVid = $hasTagLive ? '' : trim((string) ($_POST['live_video'] ?? ''));
        if ($lvImg !== '' || $lvVid !== '') {
            $live = ['img' => $lvImg, 'video' => $lvVid];
        }

        /* B站视频：{bvid} */
        $bili = null;
        $bBvid = $hasTagBili ? '' : tri3_bili_bvid((string) ($_POST['bili_bvid'] ?? ''));
        if ($bBvid !== '') {
            $bili = ['bvid' => $bBvid];
        }

        $cid = tri_moment_insert($content, $images, $cat, $private, $music, $video, $live, $bili);
        echo json_encode(['ok' => true, 'cid' => $cid]);
        break;

    case 'cats':
        /* 说说分类独立于文章分类（type='moment_cat'） */
        echo json_encode(['ok' => true, 'list' => tri_moment_cats()]);
        break;

    case 'cat_save':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $catMid = (int) ($_POST['mid'] ?? 0);
        $catName = trim((string) ($_POST['name'] ?? ''));
        $catSlug = trim((string) ($_POST['slug'] ?? ''));
        if ($catName === '' && $catSlug === '') {
            echo json_encode(['ok' => false, 'msg' => '名称不能为空']);
            exit;
        }
        $catMid = tri_moment_cat_save($catMid, $catName, $catSlug);
        echo json_encode(['ok' => true, 'mid' => $catMid]);
        break;

    case 'cat_order':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $catIds = trim((string) ($_POST['ids'] ?? ''));
        $catIdArr = array();
        foreach (explode(',', $catIds) as $seg) {
            $seg = (int) trim($seg);
            if ($seg > 0) {
                $catIdArr[] = $seg;
            }
        }
        if (empty($catIdArr)) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        /* 按提交顺序更新 metas.order（前台 tri_moment_cats() 按 order ASC 输出） */
        $catDb = Db::get();
        foreach ($catIdArr as $catIdx => $catMid) {
            $catDb->query($catDb->update('table.metas')->rows(['order' => $catIdx + 1])
                ->where('mid = ? AND type = ?', $catMid, 'moment_cat'));
        }
        echo json_encode(['ok' => true]);
        break;

    case 'cat_del':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $catMid = (int) ($_POST['mid'] ?? 0);
        if ($catMid <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        tri_moment_cat_delete($catMid);
        echo json_encode(['ok' => true]);
        break;

    case 'view':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $cid = (int) ($_POST['cid'] ?? 0);
        if ($cid <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        $m = tri_moment_get($cid);
        if (!$m) {
            echo json_encode(['ok' => false, 'msg' => '说说不存在']);
            exit;
        }
        $m['text'] = (string) $m['text'];
        $ga = [];
        foreach ((array) $m['ga'] as $g) {
            $ga[] = (string) ($g['src'] ?? '');
        }
        $m['ga'] = $ga;
        $m['bili'] = !empty($m['bili']['bvid']) ? ['bvid' => (string) $m['bili']['bvid']] : null;
        echo json_encode(['ok' => true, 'data' => $m]);
        break;

    case 'edit':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无发布权限']);
            exit;
        }
        $cid = (int) ($_POST['cid'] ?? 0);
        if ($cid <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '') {
            echo json_encode(['ok' => false, 'msg' => '内容不能为空']);
            exit;
        }
        if (mb_strlen($content) > 2000) {
            echo json_encode(['ok' => false, 'msg' => '内容过长（最多 2000 字）']);
            exit;
        }
        /* 图文混排优先：正文已含某媒体标记时，忽略独立媒体字段，避免重复显示 */
        $hasTagImg   = strpos($content, '[img]') !== false;
        $hasTagMusic = strpos($content, '[music]') !== false;
        $hasTagVideo = strpos($content, '[video]') !== false;
        $hasTagLive  = strpos($content, '[live]') !== false;
        $hasTagBili  = strpos($content, '[bili]') !== false;
        $images = [];
        $rawImgs = $hasTagImg ? '' : (string) ($_POST['images'] ?? '');
        foreach (preg_split('/\r\n|\r|\n/', $rawImgs) as $line) {
            $line = trim($line);
            if ($line !== '' && preg_match('#^https?://#i', $line)) {
                $images[] = ['src' => $line, 'thumb' => $line];
            }
        }
        if (count($images) > 9) {
            echo json_encode(['ok' => false, 'msg' => '最多 9 张图']);
            exit;
        }
        $private = !empty($_POST['private']);
        $cat = trim((string) ($_POST['cat'] ?? ''));
        $music = null;
        $mSrv = $hasTagMusic ? '' : trim((string) ($_POST['music_server'] ?? ''));
        $mId = $hasTagMusic ? '' : trim((string) ($_POST['music_id'] ?? ''));
        if ($mSrv !== '' && $mId !== '') {
            $music = [
                'server' => $mSrv === 'qq' ? 'tencent' : ($mSrv === 'local' ? 'local' : 'netease'),
                'id'     => $mId,
                'title'  => trim((string) ($_POST['music_title'] ?? '')),
                'artist' => trim((string) ($_POST['music_artist'] ?? '')),
                'pic'    => trim((string) ($_POST['music_pic'] ?? '')),
            ];
        }
        $video = null;
        $vSrc = $hasTagVideo ? '' : trim((string) ($_POST['video_src'] ?? ''));
        if ($vSrc !== '') {
            $video = [
                'src'    => $vSrc,
                'poster' => trim((string) ($_POST['video_poster'] ?? '')),
            ];
        }
        $live = null;
        $lvImg = $hasTagLive ? '' : trim((string) ($_POST['live_img'] ?? ''));
        $lvVid = $hasTagLive ? '' : trim((string) ($_POST['live_video'] ?? ''));
        if ($lvImg !== '' || $lvVid !== '') {
            $live = ['img' => $lvImg, 'video' => $lvVid];
        }
        $bili = null;
        $bBvid = $hasTagBili ? '' : tri3_bili_bvid((string) ($_POST['bili_bvid'] ?? ''));
        if ($bBvid !== '') {
            $bili = ['bvid' => $bBvid];
        }
        $ok = tri_moment_update($cid, $content, $images, $private, $music, $video, $live, $cat, $bili);
        echo json_encode(['ok' => $ok, 'msg' => $ok ? '' : '说说不存在']);
        break;

    case 'del':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $cid = (int) ($_POST['cid'] ?? 0);
        if ($cid <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        tri_moment_delete($cid);
        echo json_encode(['ok' => true]);
        break;

    /* 批量删除说说（cids 逗号分隔，连带字段） */
    case 'batch_del':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $rawCids = $_POST['cids'] ?? '';
        $cids = array();
        foreach (preg_split('/[,\s]+/', is_array($rawCids) ? implode(',', $rawCids) : (string) $rawCids) as $triC) {
            $triC = (int) trim((string) $triC);
            if ($triC > 0) { $cids[] = $triC; }
        }
        if (empty($cids)) {
            echo json_encode(['ok' => false, 'msg' => '请选择要删除的说说']);
            exit;
        }
        foreach ($cids as $triC) {
            tri_moment_delete($triC);
        }
        echo json_encode(['ok' => true, 'count' => count($cids)]);
        break;

    case 'like':
        $cid = (int) ($_POST['cid'] ?? 0);
        if ($cid <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        $row = Db::get()->fetchRow(Db::get()->select()->from('table.contents')
            ->where('cid = ? AND type = ?', $cid, 'moment'));
        if (!$row) {
            echo json_encode(['ok' => false, 'msg' => '说说不存在']);
            exit;
        }
        $n = tri_moment_like($cid);
        echo json_encode(['ok' => true, 'like' => $n]);
        break;

    case 'sticky':
        if (!$isAdmin) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $cid = (int) ($_POST['cid'] ?? 0);
        if ($cid <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        tri_moment_toggle_sticky($cid);
        echo json_encode(['ok' => true]);
        break;

    case 'list':
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $per = min(50, max(1, (int) ($_POST['per'] ?? 20)));
        $filter = (string) ($_POST['filter'] ?? 'all');
        if (!in_array($filter, ['all', 'sticky', 'private'], true)) { $filter = 'all'; }
        $cat = trim((string) ($_POST['cat'] ?? ''));
        $rows = tri_moment_list($page, $per, $isAdmin, $filter, $cat);
        foreach ($rows as &$r) {
            $r['date'] = date('Y-m-d H:i', (int) $r['created']);
            $r['text'] = (string) $r['text'];
        }
        unset($r);
        echo json_encode([
            'ok'    => true,
            'list'  => $rows,
            'total' => tri_moment_count($isAdmin, $filter, $cat),
            'admin' => $isAdmin,
        ]);
        break;

    default:
        echo json_encode(['ok' => false, 'msg' => '未知操作']);
}
