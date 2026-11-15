<?php

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

function tri_redis_available(): bool
{
    if (class_exists('TriRedisCache')) {
        return true;
    }

    if (!class_exists('Typecho\\Plugin') || !Typecho\Plugin::exists('TriRedisCache')) {
        return false;
    }

    $pluginFile = __TYPECHO_ROOT_DIR__ . '/usr/plugins/TriRedisCache/Plugin.php';
    if (!is_file($pluginFile)) {
        return false;
    }

    require_once $pluginFile;
    return class_exists('TriRedisCache');
}

function tri_redis_get(string $key, string $group = 'default')
{
    if (!tri_redis_available()) {
        return null;
    }
    try {
        return TriRedisCache::get($key, $group);
    } catch (Throwable $e) {
        return null;
    }
}

function tri_redis_set(string $key, $value, string $group = 'default', ?int $ttl = null): bool
{
    if (!tri_redis_available()) {
        return false;
    }
    try {
        return TriRedisCache::set($key, $value, $group, $ttl);
    } catch (Throwable $e) {
        return false;
    }
}

function tri_redis_delete(string $key, string $group = 'default'): bool
{
    if (!tri_redis_available()) {
        return false;
    }
    try {
        return TriRedisCache::delete($key, $group);
    } catch (Throwable $e) {
        return false;
    }
}

function tri_redis_flush(): bool
{
    if (!tri_redis_available()) {
        return false;
    }
    try {
        return TriRedisCache::flush();
    } catch (Throwable $e) {
        return false;
    }
}

function tri_redis_remember(string $key, string $group, callable $loader, ?int $ttl = null)
{
    $cached = tri_redis_get($key, $group);
    if ($cached !== null) {
        return $cached;
    }

    $value = $loader();
    tri_redis_set($key, $value, $group, $ttl);
    return $value;
}

function tri_redis_invalidate(array $groups, string $reason = ''): bool
{
    if (!tri_redis_available()) {
        return false;
    }

    try {
        if (method_exists('TriRedisCache', 'purge')) {
            return TriRedisCache::purge($groups, $reason);
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}
