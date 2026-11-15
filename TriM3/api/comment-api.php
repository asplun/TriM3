<?php
/**
 * TriM3 评论 AJAX 接口（说说页弹窗评论区）
 *
 * GET  action=list&cid=X   返回评论列表 HTML（复用评论区渲染函数）
 * POST action=post         提交评论（同源 referer 校验 + 基础防刷）
 *
 * 独立引导 Typecho 环境（不依赖主题渲染时机）。
 */

require_once dirname(__FILE__, 5) . '/config.inc.php';

if (!defined('__TYPECHO_ROOT_URL__')) {
    $triScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('__TYPECHO_ROOT_URL__', $triScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

\Widget\Init::alloc();

/* 主题函数（tri_opt / tri3_gravatar_url / 评论区渲染函数等） */
require_once dirname(__DIR__) . '/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$triAction = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

/* 同源校验（防跨站提交） */
$triRef = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
$triRefHost = $triRef !== '' ? parse_url($triRef, PHP_URL_HOST) : '';
if ($triRefHost !== '' && $triRefHost !== ($_SERVER['HTTP_HOST'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => '非法来源']);
    exit;
}

$triDb = Typecho_Db::get();

/* 取文章/页面作者 id（渲染博主判断用） */
function tri3_cmt_archive($cid)
{
    $row = Typecho_Db::get()->fetchRow(Typecho_Db::get()->select('authorId')->from('table.contents')
        ->where('cid = ? AND type IN (\'post\', \'page\', \'moment\')', (int) $cid));
    if (!$row) {
        return null;
    }
    $a = new stdClass();
    $a->cid = (int) $cid;
    $a->authorId = (int) $row['authorId'];
    return $a;
}

switch ($triAction) {
    /* ================= 评论列表 ================= */
    case 'list':
        $cid = (int) ($_GET['cid'] ?? 0);
        $archive = tri3_cmt_archive($cid);
        if (!$archive) {
            echo json_encode(['ok' => false, 'msg' => '内容不存在']);
            exit;
        }
        ob_start();
        tri3_render_comment_tree($archive);
        $listHtml = ob_get_clean();

        $cnt = (int) $triDb->fetchObject($triDb->select(array('COUNT(*)' => 'num'))->from('table.comments')
            ->where('cid = ?', $cid)
            ->where('type = ?', 'comment')
            ->where('status = ?', 'approved'))->num;

        echo json_encode(['ok' => true, 'html' => $listHtml, 'count' => $cnt]);
        break;

    /* ================= 提交评论 ================= */
    case 'post':
        if (!tri3_csrf_ok()) {
            echo json_encode(['ok' => false, 'msg' => '非法来源']);
            exit;
        }
        /* 双保险：确保评论表 qq 列存在（新库 / 迁移库首次使用即自动补齐，避免插入报 500） */
        tri3_ensure_qq_column();
        /* 全局限速（IP 滑动窗口），与原生通道统一 */
        if (function_exists('tri3_comment_rate_limit')) {
            $triRate = tri3_comment_rate_limit();
            if (!$triRate['ok']) {
                echo json_encode(['ok' => false, 'msg' => $triRate['msg']]);
                exit;
            }
        }
        $cid = (int) ($_POST['cid'] ?? 0);
        $archive = tri3_cmt_archive($cid);
        if (!$archive) {
            echo json_encode(['ok' => false, 'msg' => '内容不存在']);
            exit;
        }

        $options = Typecho_Widget::widget('Widget_Options');
        $user = Typecho_Widget::widget('Widget_User');
        $logged = $user->hasLogin();

        $author = trim((string) ($_POST['author'] ?? ''));
        $mail   = trim((string) ($_POST['mail'] ?? ''));
        $url    = trim((string) ($_POST['url'] ?? ''));
        $text   = trim((string) ($_POST['text'] ?? ''));
        $parent = max(0, (int) ($_POST['parent'] ?? 0));
        $qq = preg_replace('/\D/', '', (string) ($_POST['qq'] ?? ''));

        if ($text === '') {
            echo json_encode(['ok' => false, 'msg' => '评论内容不能为空']);
            exit;
        }
        if (mb_strlen($text) > 1000) {
            echo json_encode(['ok' => false, 'msg' => '评论内容过长（最多 1000 字）']);
            exit;
        }
        /* 图片语法：仅 http(s)，最多 9 张 */
        preg_match_all('/!\[[^\]]*\]\((https?:\/\/[^)\s]+)\)/', $text, $imgM);
        if (count($imgM[0]) > 9) {
            echo json_encode(['ok' => false, 'msg' => '图片最多 9 张']);
            exit;
        }
        if (!$logged) {
            if ($author === '') {
                echo json_encode(['ok' => false, 'msg' => '请填写昵称']);
                exit;
            }
            if (mb_strlen($author) > 50) {
                echo json_encode(['ok' => false, 'msg' => '昵称过长']);
                exit;
            }
            if ($options->commentsRequireMail && $mail === '') {
                echo json_encode(['ok' => false, 'msg' => '请填写邮箱']);
                exit;
            }
            if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['ok' => false, 'msg' => '邮箱格式不正确']);
                exit;
            }
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                echo json_encode(['ok' => false, 'msg' => '网站地址格式不正确']);
                exit;
            }
        }

        /* 父评论校验：必须属于同一 cid 且存在 */
        if ($parent > 0) {
            $pRow = $triDb->fetchRow($triDb->select()->from('table.comments')
                ->where('coid = ?', $parent)
                ->where('cid = ?', $cid));
            if (!$pRow) {
                $parent = 0;
            }
        }

        /* 防刷：同一 IP 评论间隔（默认 60 秒） */
        /* CDN 后面必须取真实访客 IP（XFF），否则所有访客共享一个 60 秒间隔 */
        $triIp = function_exists('tri3_client_ip') ? tri3_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $interval = max(10, (int) ($options->commentsPostInterval ?? 60));
        $lastRow = $triDb->fetchRow($triDb->select('created')->from('table.comments')
            ->where('ip = ?', $triIp)
            ->order('table.comments.coid', Typecho_Db::SORT_DESC)
            ->limit(1));
        if ($lastRow && (time() - (int) $lastRow['created']) < $interval) {
            echo json_encode(['ok' => false, 'msg' => '评论太频繁了，稍后再试']);
            exit;
        }

        /* 评论状态：后台设置需审核则 waiting */
        $status = $options->commentsRequireModeration ? 'waiting' : 'approved';
        /* 管理员/已登录站长评论直接通过 */
        $isOwner = $logged && (int) $user->uid === (int) $archive->authorId;
        if ($isOwner) {
            $status = 'approved';
        }

        /* ============ 垃圾评论过滤：规则引擎 + 豆包 AI（可选层） ============ */
        if (!$isOwner) {
            $triSpamData = array(
                'text'   => $text,
                'author' => $author,
                'mail'   => $mail,
                'ip'     => $triIp,
                'agent'  => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
                'hp'     => (string) ($_POST['tri3_hp'] ?? ''),
            );
            $triSpamR = tri3_spam_check($triSpamData);
            if (!$triSpamR['ok']) {
                if ($triSpamR['silent']) {
                    /* 蜜罐命中：静默成功但不入库（bot 无感知，零打扰） */
                    echo json_encode(['ok' => true, 'silent' => true]);
                    exit;
                }
                if ($triSpamR['pending']) {
                    $status = 'waiting';
                } else {
                    echo json_encode(['ok' => false, 'msg' => '评论未通过内容检查，请修改后重试']);
                    exit;
                }
            }
            /* 豆包 AI 语义层：scope=全部 或 规则放行且当前为通过 时调用；
               AI 只「加刑」不「减刑」，且调用失败一律放行（防过度防御） */
            $triS = tri3_spam_settings();
            if ($triS['aiEnable'] && ($triS['aiScope'] === 'all' || ($status === 'approved' && $triSpamR['ok']))) {
                $triAiR = tri3_spam_ai_check($triSpamData);
                if ($triAiR !== null && !$triAiR['ok']) {
                    if ($triAiR['pending']) {
                        $status = 'waiting';
                    } else {
                        echo json_encode(['ok' => false, 'msg' => '评论未通过内容检查，请修改后重试']);
                        exit;
                    }
                }
            }
        }

        $insert = array(
            'cid'      => $cid,
            'created'  => time(),
            'author'   => $logged ? (string) $user->screenName : $author,
            'authorId' => $logged ? (int) $user->uid : 0,
            'ownerId'  => (int) $archive->authorId,
            'mail'     => $logged ? (string) $user->mail : $mail,
            'url'      => $logged ? (string) $user->url : $url,
            'ip'       => $triIp,
            'agent'    => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
            'text'     => $text,
            'type'     => 'comment',
            'status'   => $status,
            'parent'   => $parent,
            'qq'       => $qq,
        );
        if ($qq !== '') {
            @setcookie('tri3_comment_qq', $qq, time() + 2592000, '/');
        }
        $triDb->query($triDb->insert('table.comments')->rows($insert));

        /* 同步内容评论数缓存：仅审核通过的评论计入（与删除分支逻辑一致） */
        if ($status === 'approved') {
            $triDb->query($triDb->update('table.contents')
                ->expression('commentsNum', 'COALESCE(commentsNum, 0) + 1')
                ->where('cid = ?', $cid));
        }

        /* 新评论 coid（cid 内 MAX(coid) 即刚插入的最新评论） */
        $newCoid = (int) $triDb->fetchObject($triDb->select(array('MAX(coid)' => 'mx'))->from('table.comments')
            ->where('cid = ?', $cid))->mx;

        /* 邮件通知：访客评论→站长；站长回复→被回复访客 */
        $insert['pageurl'] = (string) ($_POST['pageurl'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
        tri3_notify_comment($insert);

        /* 回复后重新渲染列表 */
        ob_start();
        tri3_render_comment_tree($archive);
        $listHtml = ob_get_clean();
        $cnt = (int) $triDb->fetchObject($triDb->select(array('COUNT(*)' => 'num'))->from('table.comments')
            ->where('cid = ?', $cid)
            ->where('type = ?', 'comment')
            ->where('status = ?', 'approved'))->num;

        echo json_encode(['ok' => true, 'html' => $listHtml, 'count' => $cnt, 'waiting' => $status === 'waiting', 'coid' => $newCoid]);
        break;

    /* ================= 删除评论（仅管理员） ================= */
    case 'delete':
        if (!tri3_csrf_ok()) {
            echo json_encode(['ok' => false, 'msg' => '非法来源']);
            exit;
        }
        $u = Typecho_Widget::widget('Widget_User');
        if (!$u->hasLogin() || !$u->pass('administrator')) {
            echo json_encode(['ok' => false, 'msg' => '无权限']);
            exit;
        }
        $coid = (int) ($_POST['coid'] ?? 0);
        if ($coid <= 0) {
            echo json_encode(['ok' => false, 'msg' => '参数错误']);
            exit;
        }
        $cmt = $triDb->fetchRow($triDb->select()->from('table.comments')->where('coid = ?', $coid));
        if (!$cmt) {
            echo json_encode(['ok' => false, 'msg' => '评论不存在']);
            exit;
        }
        /* 删除该评论及其子回复 */
        $triDb->query($triDb->delete('table.comments')->where('coid = ? OR parent = ?', $coid, $coid));
        /* 同步内容评论数 */
        $cid = (int) $cmt['cid'];
        $cnt = (int) $triDb->fetchObject($triDb->select(array('COUNT(*)' => 'num'))->from('table.comments')
            ->where('cid = ?', $cid)
            ->where('type = ?', 'comment')
            ->where('status = ?', 'approved'))->num;
        $triDb->query($triDb->update('table.contents')->rows(array('commentsNum' => $cnt))->where('cid = ?', $cid));
        echo json_encode(['ok' => true, 'count' => $cnt]);
        break;

    default:
        echo json_encode(['ok' => false, 'msg' => '未知操作']);
}
