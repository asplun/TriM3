# TriRedisCache

Redis object and anonymous page cache for Typecho, designed for the TriM3 theme.

## Features

- Redis object cache API
- Anonymous full-page cache for Typecho front-end pages
- Automatic invalidation when posts, pages, attachments and comments change
- phpredis support
- Bundled Predis fallback
- Typecho plugin configuration screen
- Live Redis dashboard
- Admin-only status endpoint
- Admin-only cache flush endpoint
- Safe failure when Redis is unavailable

## Dashboard

The plugin settings page shows:

- Connection status
- Redis version
- Total keys
- Memory usage
- Uptime
- Connected clients
- Operations per second
- Total commands
- Plugin-prefixed key count
- Keys with expiry
- Average TTL
- Hit rate
- Memory details
- Group distribution
- Key samples
- Site-prefix-only cache clearing
- Page and object cache counts
- Last invalidation reason

## Installation

1. Copy `TriRedisCache` to:

   ```text
   usr/plugins/TriRedisCache
   ```

2. Open Typecho admin.
3. Go to `控制台 -> 插件`.
4. Enable `TriRedisCache`.
5. Configure Redis connection settings.

## Theme API

```php
TriRedisCache::get($key, $group = 'default');
TriRedisCache::set($key, $value, $group = 'default', $ttl = null);
TriRedisCache::delete($key, $group = 'default');
TriRedisCache::flush();
TriRedisCache::status();
TriRedisCache::purge(['page', 'fusion'], 'reason');
```

TriM3 also provides safe wrapper functions:

```php
tri_redis_get($key, $group = 'default');
tri_redis_set($key, $value, $group = 'default', $ttl = null);
tri_redis_delete($key, $group = 'default');
tri_redis_flush();
tri_redis_remember($key, $group, function () { /* load value */ }, $ttl);
tri_redis_invalidate(['page', 'fusion'], 'reason');
```

These wrappers return cache misses or `false` when the plugin is disabled.

## Page Cache Rules

- Only anonymous `GET` requests are cached.
- Logged-in users, POST requests, query strings, admin/API/action paths, search, Feed and 404 pages are excluded.
- Protected posts and responses with redirects, cookies or non-HTML content are excluded.
- Pages are invalidated automatically on content and comment changes.
- Clearing cache only deletes keys under the configured site prefix.

TriM3 integrates these object-cache groups:

- `page`: anonymous HTML pages
- `moments`: moment lists, counts and categories
- `fusion`: fused post/moment timelines
- `albums`: albums, album details and photo lists
- `remote`: Douban and Bangumi data
- `weather`: QWeather data
- `geo`: IP location data
- `comments`: approved comment counts

## Admin Endpoint

```text
/usr/plugins/TriRedisCache/action.php?action=status
```

Flush cache:

```text
POST /usr/plugins/TriRedisCache/action.php?action=flush
```

Both endpoints require an administrator login.
