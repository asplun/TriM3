<?php
/** Admin-only unread comment notifications, including comments awaiting moderation. */
require_once dirname(__FILE__, 5) . '/config.inc.php';
if (!defined('__TYPECHO_ROOT_URL__')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('__TYPECHO_ROOT_URL__', $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}
\Widget\Init::alloc();
require_once dirname(__DIR__) . '/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$action = isset($_GET['action']) ? (string) $_GET['action'] : 'list';
$db = Typecho_Db::get();
$user = Typecho_Widget::widget('Widget_User');
$latestRow = $db->fetchObject($db->select('coid')->from('table.comments')->where('type = ?', 'comment')->order('coid', Typecho_Db::SORT_DESC)->limit(1));
$latestId = $latestRow ? (int) $latestRow->coid : 0;
/* First use starts at the current tail so historical comments do not appear as unread. */
$readRow = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'tri3_notify_read_coid')->where('user = ?', 0)->limit(1));
if (!$readRow) {
    $db->query($db->insert('table.options')->rows(array('name' => 'tri3_notify_read_coid', 'user' => 0, 'value' => (string) $latestId)));
    $readRow = array('value' => (string) $latestId);
}
$lastRead = max(0, (int) $readRow['value']);
/* 匿名端只拿评论 ID 用于提醒，不返回内容或审核状态。 */
if ($action === 'badge') {
    $unreadRow = $db->fetchObject($db->select('coid')->from('table.comments')->where('type = ?', 'comment')->where('coid > ?', $lastRead)->limit(1));
    $unread = (bool) $unreadRow;
    echo json_encode(array('ok' => true, 'unread' => $unread), JSON_UNESCAPED_UNICODE);
    exit;
}
if (!$user->hasLogin()) {
    echo json_encode(array('ok' => false, 'loginRequired' => true, 'msg' => '请先登录后查看消息'), JSON_UNESCAPED_UNICODE);
    exit;
}
if (!$user->pass('administrator')) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'msg' => '仅管理员可查看评论审核通知'), JSON_UNESCAPED_UNICODE);
    exit;
}
$stateKey = 'tri3_notify_read_coid';
$stateRow = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', $stateKey)->where('user = ?', 0)->limit(1));
$lastRead = $stateRow ? max(0, (int) $stateRow['value']) : 0;
if ($action === 'read') {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
    if (function_exists('tri3_csrf_ok') && !tri3_csrf_ok()) { http_response_code(403); echo json_encode(array('ok' => false, 'msg' => '请求校验失败'), JSON_UNESCAPED_UNICODE); exit; }
    $seen = max(0, (int) ($_POST['coid'] ?? 0));
    $latest = $db->fetchObject($db->select('coid')->from('table.comments')->where('type = ?', 'comment')->order('coid', Typecho_Db::SORT_DESC)->limit(1));
    $seen = min($seen, $latest ? (int) $latest->coid : 0);
    if ($seen > $lastRead) {
        if ($stateRow) $db->query($db->update('table.options')->rows(array('value' => (string) $seen))->where('name = ?', $stateKey)->where('user = ?', 0));
        else $db->query($db->insert('table.options')->rows(array('name' => $stateKey, 'user' => 0, 'value' => (string) $seen)));
        $lastRead = $seen;
    }
    echo json_encode(array('ok' => true, 'lastRead' => $lastRead), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action !== 'list') {
    echo json_encode(array('ok' => false, 'msg' => '不支持的操作'), JSON_UNESCAPED_UNICODE);
    exit;
}
$limit = max(1, min(30, (int) ($_GET['limit'] ?? 20)));
$rows = $db->fetchAll($db->select('table.comments.coid', 'table.comments.cid', 'table.comments.author', 'table.comments.text', 'table.comments.created', 'table.comments.status', 'table.contents.title', 'table.contents.type', 'table.contents.slug')
    ->from('table.comments')
    ->join('table.contents', 'table.comments.cid = table.contents.cid', Typecho_Db::INNER_JOIN)
    ->where('table.comments.type = ?', 'comment')->where('table.comments.coid > ?', $lastRead)
    ->order('table.comments.coid', Typecho_Db::SORT_ASC)->limit($limit));
$items = array();
$maxId = $lastRead;
foreach ($rows as $row) {
    $id = (int) $row['coid'];
    $maxId = max($maxId, $id);
    $url = rtrim((string) Helper::options()->siteUrl, '/') . '/#comment-' . $id;
    try {
        if (in_array((string) $row['type'], array('post', 'page'), true)) {
            $url = Typecho_Router::url('post', array('cid' => (int) $row['cid'], 'slug' => (string) $row['slug']), (string) Helper::options()->index) . '#comment-' . $id;
        } elseif ((string) $row['type'] === 'moment' && function_exists('tri3_menu_items')) {
            foreach (tri3_menu_items() as $menu) {
                $path = parse_url((string) ($menu['url'] ?? ''), PHP_URL_PATH) ?: '';
                if (($menu['type'] ?? '') === 'moment' || (($menu['type'] ?? '') === 'link' && strpos(basename(rtrim($path, '/')), 'moment') !== false)) {
                    $url = (string) $menu['url'] . '?mom_cid=' . (int) $row['cid'] . '#comment-' . $id;
                    break;
                }
            }
        }
    } catch (Throwable $e) {}
    $items[] = array('id' => $id, 'author' => (string) $row['author'], 'text' => trim(mb_substr(strip_tags((string) $row['text']), 0, 160)), 'title' => (string) $row['title'], 'status' => (string) $row['status'], 'created' => (int) $row['created'], 'url' => $url);
}
$pending = (int) $db->fetchObject($db->select(array('COUNT(*)' => 'n'))->from('table.comments')->where('type = ?', 'comment')->where('status = ?', 'waiting'))->n;
$badge = $db->fetchObject($db->select('coid')->from('table.comments')->where('type = ?', 'comment')->where('coid > ?', $lastRead)->order('coid', Typecho_Db::SORT_ASC)->limit(1));
echo json_encode(array('ok' => true, 'items' => $items, 'lastRead' => $lastRead, 'maxId' => $maxId, 'pending' => $pending, 'unread' => $badge ? 1 : 0), JSON_UNESCAPED_UNICODE);
