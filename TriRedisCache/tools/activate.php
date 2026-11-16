<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$root = dirname(__DIR__, 4);
require_once $root . '/config.inc.php';

$db = \Typecho\Db::get();
$row = $db->fetchRow(
    $db->select()->from('table.options')->where('name = ?', 'plugins')
);

$plugins = is_array($row) && isset($row['value'])
    ? json_decode((string) $row['value'], true)
    : [];

if (!is_array($plugins)) {
    $plugins = ['activated' => [], 'handles' => []];
}
$plugins['activated'] = $plugins['activated'] ?? [];
$plugins['handles'] = $plugins['handles'] ?? [];

\Typecho\Plugin::init($plugins);
if (isset($plugins['activated']['TriRedisCache'])) {
    \Typecho\Plugin::deactivate('TriRedisCache');
}

require_once dirname(__DIR__) . '/Plugin.php';
TriRedisCache_Plugin::activate();
\Typecho\Plugin::activate('TriRedisCache');
$plugins = \Typecho\Plugin::export();

$configName = 'plugin:TriRedisCache';
$configExists = $db->fetchRow(
    $db->select()->from('table.options')->where('name = ?', $configName)
);
$defaultConfig = json_encode([
    'client' => 'auto',
    'host' => '127.0.0.1',
    'port' => '6379',
    'password' => '',
    'database' => '0',
    'prefix' => 'tri',
    'default_ttl' => '300',
    'page_cache' => '1',
    'page_ttl' => '600',
]);

if (!$configExists) {
    $db->query(
        $db->insert('table.options')->rows([
            'name' => $configName,
            'user' => 0,
            'value' => $defaultConfig,
        ])
    );
}

$value = json_encode($plugins, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($row) {
    $db->query(
        $db->update('table.options')->rows(['value' => $value])->where('name = ?', 'plugins')
    );
} else {
    $db->query(
        $db->insert('table.options')->rows(['name' => 'plugins', 'user' => 0, 'value' => $value])
    );
}

echo "TriRedisCache activated\n";
