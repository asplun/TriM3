<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/ClientInterface.php';
require __DIR__ . '/../src/NullClient.php';
require __DIR__ . '/../src/PredisClient.php';
require __DIR__ . '/../src/Cache.php';

$config = [
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => '',
    'database' => 15,
    'prefix' => 'tri-test',
    'default_ttl' => 60,
];

$cache = new TriRedisCache_Cache(new TriRedisCache_PredisClient($config), $config);
$cache->flush();

if ($cache->get('integration') !== null) {
    echo "FAIL: integration key should start empty\n";
    exit(1);
}

$value = ['ok' => true, 'time' => time()];
if (!$cache->set('integration', $value, 'tests', 30)) {
    echo "FAIL: Redis write failed\n";
    exit(1);
}

if ($cache->get('integration', 'tests') !== $value) {
    echo "FAIL: Redis read failed\n";
    exit(1);
}

if (!$cache->delete('integration', 'tests')) {
    echo "FAIL: Redis delete failed\n";
    exit(1);
}

if ($cache->get('integration', 'tests') !== null) {
    echo "FAIL: Redis key was not deleted\n";
    exit(1);
}

echo "PASS: TriRedisCache Redis integration\n";
