<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../src/ClientInterface.php';
require __DIR__ . '/../src/ArrayClient.php';
require __DIR__ . '/../src/NullClient.php';
require __DIR__ . '/../src/Cache.php';
require __DIR__ . '/../src/PageCache.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }
}

$client = new TriRedisCache_ArrayClient();
$cache = new TriRedisCache_Cache($client, [
    'prefix' => 'test:',
    'default_ttl' => 120,
]);

test_assert($cache->get('missing') === null, 'Missing key returns null.');
test_assert($cache->getStats()['misses'] === 1, 'Missing key increments miss count.');

$value = ['name' => 'TriM3', 'tags' => ['blog', 'redis']];
test_assert($cache->set('theme/config', $value, 'options'), 'Set returns true.');
test_assert($cache->get('theme/config', 'options') === $value, 'Array value round-trips.');
test_assert($cache->getStats()['hits'] === 1, 'Hit increments hit count.');
test_assert($client->lastKey === 'test:options:theme-config', 'Key is normalized and namespaced.');
test_assert($client->lastTtl === 120, 'Null TTL uses default TTL.');

test_assert($cache->set('long', 'value', 'default', 0), 'Zero TTL is accepted.');
test_assert($client->lastTtl === 0, 'Zero TTL means no expiration.');
test_assert($cache->set('negative', 'value', 'default', -5), 'Negative TTL is accepted.');
test_assert($client->lastTtl === 0, 'Negative TTL is normalized to no expiration.');

test_assert($cache->delete('theme/config', 'options'), 'Delete returns true.');
test_assert($cache->get('theme/config', 'options') === null, 'Deleted key returns null.');

$cache->set('one', 'a');
$cache->set('two', 'b');
test_assert($cache->flush(), 'Flush returns true.');
test_assert($cache->get('one') === null && $cache->get('two') === null, 'Flush removes values.');

$prefixClient = new TriRedisCache_ArrayClient();
$prefixCache = new TriRedisCache_Cache($prefixClient, [
    'prefix' => 'site-a',
    'default_ttl' => 60,
]);
$prefixCache->set('one', 'a');
$prefixClient->set('site-b:default:one', 'b', 60);
test_assert($prefixCache->flush(), 'Prefix flush returns true.');
test_assert($prefixCache->get('one') === null, 'Prefix flush removes own keys.');
test_assert($prefixClient->get('site-b:default:one') !== null, 'Prefix flush keeps other prefixes.');

$broken = new TriRedisCache_Cache(new TriRedisCache_NullClient(), [
    'prefix' => 'broken:',
    'default_ttl' => 60,
]);
test_assert($broken->get('anything') === null, 'Broken client returns cache miss.');
test_assert($broken->set('anything', 'value') === false, 'Broken client returns write failure.');
test_assert($broken->delete('anything') === false, 'Broken client returns delete failure.');
test_assert($broken->flush() === false, 'Broken client returns flush failure.');

$cache->set('page-one', 'page', 'page');
$cache->set('option-one', 'value', 'options');
test_assert($cache->deleteGroup('page'), 'Group delete returns true.');
test_assert($cache->get('page-one', 'page') === null, 'Group delete removes page keys.');
test_assert($cache->get('option-one', 'options') !== null, 'Group delete keeps other groups.');

$keyA = TriRedisCache_PageCache::makeKey('https://example.com', '/');
$keyB = TriRedisCache_PageCache::makeKey('https://example.com', '/blog.html');
$keyC = TriRedisCache_PageCache::makeKey('https://example.org', '/');
$keyD = TriRedisCache_PageCache::makeKey('https://example.com', '/', 'TriM3');
test_assert($keyA !== $keyB && $keyA !== $keyC && $keyA !== $keyD, 'Page key separates path, origin, and context.');
test_assert($keyD === TriRedisCache_PageCache::makeKey('https://example.com', '/', 'TriM3'), 'Page key is stable.');

$server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/blog.html', 'QUERY_STRING' => ''];
test_assert(TriRedisCache_PageCache::isCacheableRequest($server, []), 'Anonymous GET is cacheable.');
$postServer = $server;
$postServer['REQUEST_METHOD'] = 'POST';
$queryServer = $server;
$queryServer['QUERY_STRING'] = 'utm=1';
test_assert(!TriRedisCache_PageCache::isCacheableRequest($postServer, []), 'POST is not cacheable.');
test_assert(!TriRedisCache_PageCache::isCacheableRequest($queryServer, []), 'Query requests are not cacheable.');
$apiServer = $server;
$apiServer['REQUEST_URI'] = '/api/list-api.php';
test_assert(!TriRedisCache_PageCache::isCacheableRequest($apiServer, []), 'API requests are not page-cached.');
test_assert(!TriRedisCache_PageCache::isCacheableRequest($server, ['abc__typecho_remember_mail' => 'x'], 'abc'), 'Remember cookies are not cacheable.');
test_assert(!TriRedisCache_PageCache::isCacheableRequest($server, ['abcprotectPassword_1' => 'x'], 'abc'), 'Password cookies are not cacheable.');

test_assert(TriRedisCache_PageCache::isCacheableArchive('page'), 'Normal page archive is cacheable.');
test_assert(!TriRedisCache_PageCache::isCacheableArchive('archive'), '404 archive is not cacheable.');
test_assert(!TriRedisCache_PageCache::isCacheableArchive('search'), 'Search archive is not cacheable.');
test_assert(!TriRedisCache_PageCache::isCacheableArchive('post', true, 'secret'), 'Protected post is not cacheable.');

$responseState = ['status' => 200, 'contentType' => 'text/html', 'headers' => [], 'cookies' => []];
test_assert(TriRedisCache_PageCache::isCacheableResponse($responseState, [], 200, [], [], 'text/html'), 'Normal response is cacheable.');
$notFoundState = $responseState;
$notFoundState['status'] = 404;
test_assert(!TriRedisCache_PageCache::isCacheableResponse($notFoundState, [], 200, [], [], 'text/html'), 'Non-200 response is not cacheable.');
test_assert(!TriRedisCache_PageCache::isCacheableResponse($responseState, ['Location: /'], 200, [], [], 'text/html'), 'Redirect response is not cacheable.');
test_assert(!TriRedisCache_PageCache::isCacheableResponse($responseState, ['Set-Cookie: a=b'], 200, [], [], 'text/html'), 'Cookie response is not cacheable.');
$jsonState = $responseState;
$jsonState['contentType'] = 'application/json';
test_assert(!TriRedisCache_PageCache::isCacheableResponse($jsonState, [], 200, [], [], 'text/html'), 'JSON response is not cacheable.');
test_assert(!TriRedisCache_PageCache::isCacheableResponse($responseState, [], 200, ['a' => '1'], ['a' => '2'], 'text/html'), 'Changed cookies are not cacheable.');

echo "PASS: TriRedisCache core tests\n";
