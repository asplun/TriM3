<?php

/**
 * TriRedisCache
 *
 * @package TriRedisCache
 * @author 薄荷
 * @version 1.2.7
 * @description Redis object and anonymous page cache for Typecho and TriM3. Supports phpredis and bundled Predis.
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Plugin\PluginInterface;
use Typecho\Db;
use Typecho\Widget;
use Typecho\Widget\Helper\Form;

require_once __DIR__ . '/src/ClientInterface.php';
require_once __DIR__ . '/src/NullClient.php';
require_once __DIR__ . '/src/Cache.php';
require_once __DIR__ . '/src/PageCache.php';

final class TriRedisCache
{
    public static function get(string $key, string $group = 'default')
    {
        return TriRedisCache_Plugin::cache()->get($key, $group);
    }

    public static function set(string $key, $value, string $group = 'default', ?int $ttl = null): bool
    {
        return TriRedisCache_Plugin::cache()->set($key, $value, $group, $ttl);
    }

    public static function delete(string $key, string $group = 'default'): bool
    {
        return TriRedisCache_Plugin::cache()->delete($key, $group);
    }

    public static function flush(): bool
    {
        return TriRedisCache_Plugin::cache()->flush();
    }

    public static function status(): array
    {
        return TriRedisCache_Plugin::cache()->status();
    }

    public static function details(): array
    {
        return TriRedisCache_Plugin::cache()->client()->details();
    }

    public static function scanKeys(string $pattern, int $limit): array
    {
        return TriRedisCache_Plugin::cache()->client()->scanKeys($pattern, $limit);
    }

    public static function purge(array $groups = ['page'], string $reason = ''): bool
    {
        return TriRedisCache_Plugin::purgeGroups($groups, $reason);
    }
}

final class TriRedisCache_Plugin implements PluginInterface
{
    private static ?TriRedisCache_Cache $cache = null;

    private static ?string $pageKey = null;

    private static ?array $pageCookies = null;

    private static bool $pageBuffering = false;

    public static function activate()
    {
        try {
            $db = Db::get();
            $exists = $db->fetchRow(
                $db->select()->from('table.options')->where('name = ?', 'plugin:TriRedisCache')
            );
            if (!$exists) {
                $db->query(
                    $db->insert('table.options')->rows([
                        'name' => 'plugin:TriRedisCache',
                        'user' => 0,
                        'value' => json_encode([
                            'client' => 'auto',
                            'host' => '127.0.0.1',
                            'port' => '6379',
                            'password' => '',
                            'database' => '0',
                            'prefix' => 'tri',
                            'default_ttl' => '300',
                            'page_cache' => '1',
                            'page_ttl' => '600',
                        ]),
                    ])
                );
            }
        } catch (Throwable $e) {
        }

        \Widget\Archive::pluginHandle()->beforeRender = [self::class, 'beforeRender'];
        \Widget\Archive::pluginHandle()->afterRender = [self::class, 'afterRender'];

        \Widget\Contents\Post\Edit::pluginHandle()->finishPublish = [self::class, 'clearOnContentChange'];
        \Widget\Contents\Page\Edit::pluginHandle()->finishPublish = [self::class, 'clearOnContentChange'];
        \Widget\Contents\Post\Edit::pluginHandle()->finishDelete = [self::class, 'clearOnContentDelete'];
        \Widget\Contents\Page\Edit::pluginHandle()->finishDelete = [self::class, 'clearOnContentDelete'];
        \Widget\Contents\Attachment\Edit::pluginHandle()->finishDelete = [self::class, 'clearOnContentDelete'];
        \Widget\Contents\Post\Edit::pluginHandle()->finishMark = [self::class, 'clearOnContentMark'];
        \Widget\Contents\Page\Edit::pluginHandle()->finishMark = [self::class, 'clearOnContentMark'];

        \Widget\Feedback::pluginHandle()->finishComment = [self::class, 'clearOnComment'];
        \Widget\Comments\Edit::pluginHandle()->finishDelete = [self::class, 'clearOnCommentEdit'];
        \Widget\Comments\Edit::pluginHandle()->finishEdit = [self::class, 'clearOnCommentEdit'];
        \Widget\Comments\Edit::pluginHandle()->finishComment = [self::class, 'clearOnCommentEdit'];

        return _t('TriRedisCache 已启用：对象缓存与匿名页面缓存已接入。');
    }

    public static function deactivate()
    {
        self::$cache = null;
        return _t('TriRedisCache 已停用。');
    }

    public static function config(Form $form)
    {
        echo self::renderDashboard();

        $client = new Widget\Helper\Form\Element\Radio(
            'client',
            [
                'auto' => _t('自动'),
                'phpredis' => _t('phpredis'),
                'predis' => _t('Predis'),
            ],
            'auto',
            _t('客户端'),
            _t('自动模式优先使用 phpredis，未安装时使用内置 Predis')
        );
        $form->addInput($client);

        $host = new Widget\Helper\Form\Element\Text(
            'host',
            null,
            '127.0.0.1',
            _t('Redis 地址'),
            _t('默认本机 127.0.0.1')
        );
        $form->addInput($host);

        $port = new Widget\Helper\Form\Element\Text(
            'port',
            null,
            '6379',
            _t('Redis 端口'),
            _t('默认 6379')
        );
        $form->addInput($port);

        $password = new Widget\Helper\Form\Element\Text(
            'password',
            null,
            '',
            _t('Redis 密码'),
            _t('未设置密码请留空')
        );
        $form->addInput($password);

        $database = new Widget\Helper\Form\Element\Text(
            'database',
            null,
            '0',
            _t('Redis 数据库'),
            _t('0-15，默认 0')
        );
        $form->addInput($database);

        $prefix = new Widget\Helper\Form\Element\Text(
            'prefix',
            null,
            'tri',
            _t('Key 前缀'),
            _t('建议使用站点专属前缀，避免多站共用 Redis 时冲突')
        );
        $form->addInput($prefix);

        $ttl = new Widget\Helper\Form\Element\Text(
            'default_ttl',
            null,
            '300',
            _t('默认 TTL（秒）'),
            _t('默认 300 秒；填 0 表示不过期')
        );
        $form->addInput($ttl);

        $pageCache = new Widget\Helper\Form\Element\Radio(
            'page_cache',
            [
                '1' => _t('启用'),
                '0' => _t('关闭'),
            ],
            '1',
            _t('匿名页面缓存'),
            _t('仅缓存匿名 GET HTML 页面；登录、后台、POST、搜索、Feed、带参数请求一律不缓存')
        );
        $form->addInput($pageCache);

        $pageTtl = new Widget\Helper\Form\Element\Text(
            'page_ttl',
            null,
            '600',
            _t('页面缓存 TTL（秒）'),
            _t('默认 600 秒；文章、页面、评论变更会自动失效')
        );
        $form->addInput($pageTtl);

        echo '<div style="background:#f6f8fa;border:1px solid #d0d7de;border-radius:8px;padding:12px 16px;margin-top:16px;font-size:13px;line-height:1.8">';
        echo '<strong>主题调用 API：</strong><br>';
        echo '<code>TriRedisCache::get($key, $group)</code><br>';
        echo '<code>TriRedisCache::set($key, $value, $group, $ttl)</code><br>';
        echo '<code>TriRedisCache::delete($key, $group)</code><br>';
        echo '<code>TriRedisCache::flush()</code><br>';
        echo '<code>TriRedisCache::purge([\'page\', \'fusion\'], \'reason\')</code>';
        echo '</div>';
    }

    private static function renderDashboard(): string
    {
        try {
            $siteUrl = rtrim((string) Widget::widget('Widget_Options')->siteUrl, '/');
            $client = self::cache()->client();
            $details = $client->details();
            $config = self::configArray();
            $prefix = trim((string) ($config['prefix'] ?? 'tri'));
            $keys = $client->scanKeys($prefix . ':*', 1000);
        } catch (Throwable $e) {
            return '<div class="trc-dash trc-error">无法连接 Redis：' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        $server = $details['server'] ?? [];
        $memory = $details['memory'] ?? [];
        $keyspace = $details['keyspace'] ?? [];
        $stats = $details['stats'] ?? [];
        $clients = $details['clients'] ?? [];
        $db = (int) ($config['database'] ?? 0);
        $dbKey = 'db' . $db;
        $dbInfo = $keyspace[$dbKey] ?? [];

        $cacheStats = self::cache()->getStats();
        $hits = (int) ($cacheStats['hits'] ?? 0);
        $misses = (int) ($cacheStats['misses'] ?? 0);
        $total = $hits + $misses;
        $hitRate = $total > 0 ? round($hits / $total * 100, 2) : 0.0;

        $groups = [];
        foreach ($keys as $key) {
            $parts = explode(':', $key, 3);
            $group = $parts[1] ?? 'default';
            $groups[$group] = ($groups[$group] ?? 0) + 1;
        }
        arsort($groups);
        $groupTotal = array_sum($groups);
        $pageCount = (int) ($groups['page'] ?? 0);
        $objectCount = max(0, $groupTotal - $pageCount);
        $lastInvalidation = self::cache()->get('last_invalidation', 'meta');
        $lastInvalidationText = '-';
        if (is_array($lastInvalidation) && !empty($lastInvalidation['reason'])) {
            $lastInvalidationText = (string) $lastInvalidation['reason']
                . ' · ' . date('m-d H:i', (int) ($lastInvalidation['time'] ?? 0));
        }

        $sampleKeys = array_slice($keys, 0, 12);
        $sampleDetails = [];
        foreach ($sampleKeys as $key) {
            $sampleDetails[] = $client->keyInfo($key);
        }

        $html = '<link rel="stylesheet" href="' . htmlspecialchars($siteUrl . '/usr/plugins/TriRedisCache/assets/admin.css?v=1.2.6', ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<div class="trc-dash">';
        $html .= '<div class="trc-head"><div><span class="trc-dot"></span><strong>Redis 缓存状态</strong></div>'
            . '<div class="trc-head-right"><div class="trc-sub">'
            . htmlspecialchars((string) ($config['client'] ?? 'auto')) . ' · '
            . htmlspecialchars((string) ($config['host'] ?? '127.0.0.1')) . ':'
            . htmlspecialchars((string) ($config['port'] ?? 6379)) . ' / DB ' . $db
            . '</div><button type="button" class="trc-flush" data-endpoint="'
            . htmlspecialchars($siteUrl . '/usr/plugins/TriRedisCache/action.php?action=flush', ENT_QUOTES, 'UTF-8')
            . '">清空本站缓存</button></div></div>';

        $html .= '<div class="trc-grid">';
        $html .= self::tile('连接状态', isset($server['redis_version']) ? '正常' : '异常', isset($server['redis_version']) ? 'good' : 'bad');
        $html .= self::tile('Redis 版本', (string) ($server['redis_version'] ?? '-'), '');
        $html .= self::tile('本站键数', (string) count($keys), '');
        $html .= self::tile('内存占用', (string) ($memory['used_memory_human'] ?? '-'), '');
        $html .= self::tile('页面缓存', (string) $pageCount, '');
        $html .= self::tile('对象缓存', (string) $objectCount, '');
        $html .= '</div>';

        $html .= '<div class="trc-grid trc-grid-3">';
        $html .= self::panel('运行状态', [
            '运行时间' => ((int) ($server['uptime_in_days'] ?? 0)) . ' 天',
            '连接客户端' => (string) ($clients['connected_clients'] ?? '-'),
            '每秒操作' => (string) ($stats['instantaneous_ops_per_sec'] ?? '-'),
            '累计命令' => (string) ($stats['total_commands_processed'] ?? '-'),
        ]);
        $html .= self::panel('键空间', [
            '本站键数' => (string) count($keys),
            '扫描上限' => '1000',
            '命中率' => $hitRate . '%',
            '命中 / 未命中' => $hits . ' / ' . $misses,
            '页面 / 对象' => $pageCount . ' / ' . $objectCount,
            '最近失效' => $lastInvalidationText,
        ]);
        $html .= self::panel('内存', [
            '已用内存' => (string) ($memory['used_memory_human'] ?? '-'),
            '峰值内存' => (string) ($memory['used_memory_peak_human'] ?? '-'),
            '最大内存' => (string) ($memory['maxmemory_human'] ?? '0B'),
            '淘汰策略' => (string) ($memory['maxmemory_policy'] ?? '-'),
            '碎片率' => (string) ($memory['mem_fragmentation_ratio'] ?? '-'),
        ]);
        $html .= '</div>';

        $html .= '<div class="trc-section"><div class="trc-section-title">分组统计<span>扫描前 ' . count($keys) . ' 个键</span></div>';
        if (empty($groups)) {
            $html .= '<div class="trc-empty">暂无匹配前缀 <code>' . htmlspecialchars($prefix) . ':*</code> 的键</div>';
        } else {
            $html .= '<div class="trc-groups">';
            foreach (array_slice($groups, 0, 10, true) as $group => $count) {
                $percent = $groupTotal > 0 ? round($count / $groupTotal * 100, 1) : 0;
                $html .= '<div class="trc-group"><div class="trc-group-head"><span>' . htmlspecialchars((string) $group) . '</span><b>'
                    . $count . ' · ' . $percent . '%</b></div><div class="trc-bar"><span style="width:' . $percent . '%"></span></div></div>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        $html .= '<div class="trc-section"><div class="trc-section-title">键样本<span>前 12 个键</span></div>';
        if (empty($sampleDetails)) {
            $html .= '<div class="trc-empty">暂无键样本</div>';
        } else {
            $html .= '<div class="trc-table-wrap"><table class="trc-table"><thead><tr><th>键</th><th>类型</th><th>TTL</th><th>大小</th></tr></thead><tbody>';
            foreach ($sampleDetails as $info) {
                $html .= '<tr><td><code>' . htmlspecialchars((string) $info['key']) . '</code></td><td>'
                    . htmlspecialchars((string) $info['type']) . '</td><td>' . self::formatTtl((int) $info['ttl'])
                    . '</td><td>' . self::formatBytes((int) $info['size']) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '</div>';

        $html .= '</div>';

        $html .= '<script>(function(){var b=document.querySelector(".trc-flush");if(!b)return;b.addEventListener("click",function(){if(!confirm("确定清空当前站点缓存？"))return;b.disabled=true;b.textContent="清空中…";fetch(b.dataset.endpoint,{method:"POST",credentials:"same-origin"}).then(function(r){return r.json()}).then(function(d){if(d.ok){b.textContent="已清空";setTimeout(function(){location.reload()},600)}else{b.disabled=false;b.textContent="清空失败";alert(d.message||"清空失败")}}).catch(function(){b.disabled=false;b.textContent="清空失败";alert("网络错误")})})})();</script>';
        return $html;
    }

    private static function tile(string $label, string $value, string $state): string
    {
        return '<div class="trc-tile ' . htmlspecialchars($state) . '"><span>' . htmlspecialchars($label) . '</span><strong>' . htmlspecialchars($value) . '</strong></div>';
    }

    private static function panel(string $title, array $rows): string
    {
        $html = '<div class="trc-panel"><div class="trc-panel-title">' . htmlspecialchars($title) . '</div><dl>';
        foreach ($rows as $key => $value) {
            $html .= '<div><dt>' . htmlspecialchars((string) $key) . '</dt><dd>' . htmlspecialchars((string) $value) . '</dd></div>';
        }
        return $html . '</dl></div>';
    }

    private static function formatTtl(int $ttl): string
    {
        if ($ttl === -1) {
            return '永久';
        }
        if ($ttl <= 0) {
            return '-';
        }
        if ($ttl < 60) {
            return $ttl . ' 秒';
        }
        if ($ttl < 3600) {
            return round($ttl / 60, 1) . ' 分钟';
        }
        if ($ttl < 86400) {
            return round($ttl / 3600, 1) . ' 小时';
        }
        return round($ttl / 86400, 1) . ' 天';
    }

    private static function formatMilliseconds(int $milliseconds): string
    {
        if ($milliseconds <= 0) {
            return '-';
        }
        return self::formatTtl((int) round($milliseconds / 1000));
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }
        return round($value, $index === 0 ? 0 : 1) . ' ' . $units[$index];
    }

    public static function beforeRender(\Widget\Archive $archive): void
    {
        self::$pageKey = null;
        self::$pageBuffering = false;

        if (!self::pageCacheEnabled()) {
            return;
        }

        if (defined('TRI_REDIS_SKIP_PAGE_CACHE') && constant('TRI_REDIS_SKIP_PAGE_CACHE')) {
            return;
        }

        try {
            $cookiePrefix = \Typecho\Cookie::getPrefix();
            if (!TriRedisCache_PageCache::isCacheableRequest($_SERVER, $_COOKIE, $cookiePrefix)) {
                return;
            }

            $options = Widget::widget('Widget_Options');
            $origin = TriRedisCache_PageCache::canonicalOrigin($_SERVER, (string) $options->siteUrl);
            if ($origin === null) {
                return;
            }

            $user = Widget::widget('Widget_User');
            if ($user->hasLogin()) {
                return;
            }

            if (!self::isCacheableArchiveObject($archive)) {
                return;
            }

            $path = TriRedisCache_PageCache::requestPath($_SERVER);
            if ($path === null) {
                return;
            }

            $pageContext = (string) $options->theme;
            if (defined('TRI_REDIS_PAGE_VARIANT')) {
                $pageContext .= ':' . (string) constant('TRI_REDIS_PAGE_VARIANT');
            }
            $key = TriRedisCache_PageCache::makeKey($origin, $path, $pageContext);
            $cached = self::cache()->get($key, 'page');
            if (is_string($cached) && $cached !== '') {
                header('X-Tri-Redis-Cache: hit');
                if (!headers_sent()) {
                    \Typecho\Response::getInstance()->sendHeaders();
                }
                echo $cached;
                exit;
            }

            self::$pageKey = $key;
            self::$pageCookies = $_COOKIE;
            self::$pageBuffering = true;
            ob_start();
        } catch (Throwable $e) {
            self::$pageKey = null;
            self::$pageBuffering = false;
        }
    }

    public static function afterRender(\Widget\Archive $archive): void
    {
        if (!self::$pageBuffering) {
            return;
        }
        self::$pageBuffering = false;

        try {
            if (
                self::$pageKey === null
                || !self::isCacheableArchiveObject($archive)
                || !self::isCacheableResponse()
            ) {
                ob_end_flush();
                return;
            }

            $content = ob_get_clean();
            if (!is_string($content) || $content === '') {
                return;
            }

            echo $content;
            self::cache()->set(self::$pageKey, $content, 'page', self::pageTtl());
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
        } finally {
            self::$pageKey = null;
            self::$pageCookies = null;
        }
    }

    public static function clearOnContentChange(array $contents, $widget): void
    {
        self::purgeGroups(['page', 'fusion'], 'content updated');
    }

    public static function clearOnContentDelete(int $cid, $widget): void
    {
        self::purgeGroups(['page', 'fusion'], 'content deleted');
    }

    public static function clearOnContentMark(string $status, int $cid, $widget): void
    {
        self::purgeGroups(['page', 'fusion'], 'content status changed');
    }

    public static function clearOnComment($widget): void
    {
        self::purgeGroups(['page', 'fusion', 'comments'], 'comment updated');
    }

    public static function clearOnCommentEdit($widget): void
    {
        self::purgeGroups(['page', 'fusion', 'comments'], 'comment edited');
    }

    public static function purgeGroups(array $groups, string $reason = ''): bool
    {
        $ok = true;
        foreach (array_unique(array_filter(array_map('strval', $groups))) as $group) {
            $ok = self::cache()->deleteGroup($group) && $ok;
        }

        if ($reason !== '') {
            self::cache()->set('last_invalidation', [
                'reason' => $reason,
                'time' => time(),
            ], 'meta', 0);
        }

        return $ok;
    }

    private static function isCacheableArchiveObject(\Widget\Archive $archive): bool
    {
        $hidden = false;
        $password = '';
        try {
            if ($archive->is('single')) {
                $hidden = (bool) $archive->hidden;
                $password = (string) $archive->password;
            }
        } catch (Throwable $e) {
            return false;
        }

        return TriRedisCache_PageCache::isCacheableArchive(
            (string) $archive->getArchiveType(),
            $hidden,
            $password
        );
    }

    private static function isCacheableResponse(): bool
    {
        $state = self::responseState();
        $options = Widget::widget('Widget_Options');
        return TriRedisCache_PageCache::isCacheableResponse(
            $state,
            headers_list(),
            http_response_code(),
            self::$pageCookies ?? $_COOKIE,
            $_COOKIE,
            (string) ($options->contentType ?: 'text/html')
        );
    }

    private static function responseState(): array
    {
        $fallback = [
            'status' => 200,
            'contentType' => 'text/html',
            'headers' => [],
            'cookies' => [],
        ];

        try {
            $response = \Typecho\Response::getInstance();
            $result = $fallback;
            foreach (array_keys($fallback) as $name) {
                $property = new \ReflectionProperty($response, $name);
                $result[$name] = $property->getValue($response);
            }
            return $result;
        } catch (Throwable $e) {
            return $fallback;
        }
    }

    private static function pageCacheEnabled(): bool
    {
        return ((string) (self::configArray()['page_cache'] ?? '1')) === '1';
    }

    private static function pageTtl(): int
    {
        return max(60, (int) (self::configArray()['page_ttl'] ?? 600));
    }

    public static function personalConfig(Form $form)
    {
    }

    public static function cache(): TriRedisCache_Cache
    {
        if (self::$cache === null) {
            self::$cache = new TriRedisCache_Cache(self::makeClient(), self::configArray());
        }
        return self::$cache;
    }

    private static function makeClient(): TriRedisCache_ClientInterface
    {
        $config = self::configArray();
        $preference = (string) ($config['client'] ?? 'auto');

        $tryPhpRedis = $preference === 'phpredis' || $preference === 'auto';
        $tryPredis = $preference === 'predis' || $preference === 'auto';

        if ($tryPhpRedis && class_exists('Redis')) {
            try {
                require_once __DIR__ . '/src/PhpRedisClient.php';
                return new TriRedisCache_PhpRedisClient($config);
            } catch (Throwable $e) {
                if ($preference === 'phpredis') {
                    return new TriRedisCache_NullClient();
                }
            }
        }

        if ($tryPredis) {
            try {
                require_once __DIR__ . '/src/PredisClient.php';
                return new TriRedisCache_PredisClient($config);
            } catch (Throwable $e) {
                return new TriRedisCache_NullClient();
            }
        }

        return new TriRedisCache_NullClient();
    }

    private static function configArray(): array
    {
        try {
            $config = Widget::widget('Widget_Options')->plugin('TriRedisCache');
            return [
                'client' => (string) ($config->client ?? 'auto'),
                'host' => (string) ($config->host ?? '127.0.0.1'),
                'port' => (int) ($config->port ?? 6379),
                'password' => (string) ($config->password ?? ''),
                'database' => (int) ($config->database ?? 0),
                'prefix' => (string) ($config->prefix ?? 'tri'),
                'default_ttl' => (int) ($config->default_ttl ?? 300),
                'page_cache' => (string) ($config->page_cache ?? '1'),
                'page_ttl' => max(60, (int) ($config->page_ttl ?? 600)),
            ];
        } catch (Throwable $e) {
            return [
                'client' => 'auto',
                'host' => '127.0.0.1',
                'port' => 6379,
                'password' => '',
                'database' => 0,
                'prefix' => 'tri',
                'default_ttl' => 300,
                'page_cache' => '1',
                'page_ttl' => 600,
            ];
        }
    }
}
