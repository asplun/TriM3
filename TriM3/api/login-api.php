<?php
/**
 * TriM3 前台登录 AJAX 接口
 *
 * POST do=login   用户名/密码前台登录   name / password / remember(1)
 * POST do=logout  退出登录
 *
 * 独立引导 Typecho 环境；Cookie 前缀与后台登录保持一致（见下）。
 */

use Typecho\Widget;

require_once dirname(__FILE__, 5) . '/config.inc.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

/* 未勾选"记住我"时 Typecho 走 session 登录，必须显式启动 session */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

/* 与后台 /admin 的 rootUrl 保持一致，否则 Cookie 前缀不一致、登录态读不到 */
if (!defined('__TYPECHO_ROOT_URL__')) {
    $triScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    define('__TYPECHO_ROOT_URL__', $triScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

\Widget\Init::alloc();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$do = isset($_POST['do']) ? (string) $_POST['do'] : '';

/* 写操作 CSRF 防护（Origin 优先，缺失回退 Referer，都缺失拒绝） */
if (!tri3_csrf_ok()) {
    echo json_encode(['ok' => false, 'msg' => '非法来源']);
    exit;
}

$user = Widget::widget('Widget_User');

switch ($do) {
    case 'login':
        /* 轻量防爆破：同 IP 10 分钟内失败 ≥5 次则锁定 15 分钟；失败固定延时 0.8s */
        /* CDN 后面 REMOTE_ADDR 是节点 IP，必须取真实访客 IP，否则全站共用一个锁定计数 */
        $triIp = function_exists('tri3_client_ip') ? tri3_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $triLockDir = dirname(__DIR__) . '/cache';
        if (!is_dir($triLockDir)) { @mkdir($triLockDir, 0755, true); }
        $triLk = $triLockDir . '/login-' . md5($triIp) . '.lock';
        /* 锁文件按访客 IP 一文件，数量无上限：偶发清理 1 天前的旧锁，避免 cache/ 被撑爆 */
        if (mt_rand(1, 20) === 1) {
            foreach ((array) @glob($triLockDir . '/login-*.lock') as $triOldLk) {
                if (@filemtime($triOldLk) < time() - 86400) { @unlink($triOldLk); }
            }
        }
        $triLocked = false;
        if (is_file($triLk)) {
            $triLockData = @json_decode((string) @file_get_contents($triLk), true);
            if (is_array($triLockData)) {
                if ((int) ($triLockData['until'] ?? 0) > time()) {
                    $triLocked = true;
                } elseif ((int) ($triLockData['ts'] ?? 0) < time() - 600) {
                    @unlink($triLk);
                }
            }
        }
        if ($triLocked) {
            echo json_encode(['ok' => false, 'msg' => '尝试过于频繁，请 15 分钟后再试']);
            exit;
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $remember = !empty($_POST['remember']);
        if ($name === '' || $password === '') {
            echo json_encode(['ok' => false, 'msg' => '请输入用户名和密码']);
            exit;
        }
        /* Typecho 的 temporarily=true 不写任何持久登录态（仅当前请求有效），
           因此一律走 cookie：勾选"记住我"=365 天，不勾=会话 cookie（关浏览器失效） */
        $triOk = $user->login($name, $password, false, $remember ? 31536000 : 0);
        if ($user->hasLogin()) {
            @unlink($triLk);
            echo json_encode(['ok' => true, 'name' => $user->name]);
        } else {
            /* 失败计数 */
            $triCnt = 1;
            if (is_file($triLk)) {
                $triOld = @json_decode((string) @file_get_contents($triLk), true);
                if (is_array($triOld)) { $triCnt = (int) ($triOld['cnt'] ?? 0) + 1; }
            }
            $triUntil = $triCnt >= 5 ? time() + 900 : 0;
            @file_put_contents($triLk, json_encode(array('cnt' => $triCnt, 'ts' => time(), 'until' => $triUntil)));
            usleep(800000); /* 固定延时，拖慢爆破 */
            echo json_encode(['ok' => false, 'msg' => $triOk === false ? '用户名或密码错误' : '登录失败，请稍后再试']);
        }
        exit;

    case 'logout':
        $user->logout();
        echo json_encode(['ok' => true]);
        exit;

    default:
        echo json_encode(['ok' => false, 'msg' => '未知操作']);
        exit;
}
