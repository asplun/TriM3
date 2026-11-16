<?php

/**
 * TriRedisCache admin action endpoint.
 *
 * GET  ?action=status
 * POST ?action=flush
 */

require_once dirname(__DIR__, 3) . '/config.inc.php';

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit('Typecho root is unavailable.');
}

require_once __DIR__ . '/Plugin.php';

/* 独立入口下 Typecho 会按 /usr/plugins/... 计算 Cookie 前缀，
   导致后台登录 Cookie 读不到。这里固定为站点根，与主题 API 保持一致。 */
if (!defined('__TYPECHO_ROOT_URL__')) {
    $triRedisScheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
    if ($triRedisScheme === '') {
        $triRedisScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }
    define('__TYPECHO_ROOT_URL__', $triRedisScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
}

\Widget\Init::alloc();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function tri_redis_json(array $payload, int $status = 200): void
{
    if ($status !== 200) {
        try {
            \Typecho\Response::getInstance()->setStatus($status);
        } catch (Throwable $e) {
        }
    }
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tri_redis_is_admin(): bool
{
    try {
        $user = \Typecho\Widget::widget('Widget_User');
        return $user->hasLogin() && $user->pass('administrator');
    } catch (Throwable $e) {
        return false;
    }
}

function tri_redis_same_origin(): bool
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($origin === '') {
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($referer === '') {
            return false;
        }
        $parts = parse_url($referer);
        $origin = isset($parts['scheme'], $parts['host'])
            ? strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            : '';
    } else {
        $parts = parse_url($origin);
        $origin = isset($parts['scheme'], $parts['host'])
            ? strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            : '';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $origin === $scheme . '://' . $host;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';

if (!tri_redis_is_admin()) {
    tri_redis_json(['ok' => false, 'message' => 'administrator login required'], 403);
}

if ($action === 'status') {
    tri_redis_json([
        'ok' => true,
        'status' => TriRedisCache::status(),
    ]);
}

if ($action === 'flush') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !tri_redis_same_origin()) {
        tri_redis_json(['ok' => false, 'message' => 'invalid origin'], 403);
    }
    $ok = TriRedisCache::flush();
    tri_redis_json([
        'ok' => $ok,
        'message' => $ok ? 'cache flushed' : 'unable to flush cache',
    ]);
}

tri_redis_json(['ok' => false, 'message' => 'unknown action'], 400);
