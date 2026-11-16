<?php

declare(strict_types=1);

final class TriRedisCache_PredisClient implements TriRedisCache_ClientInterface
{
    private Predis\Client $client;

    public function __construct(array $config)
    {
        if (!class_exists('Predis\\Client')) {
            require_once __DIR__ . '/../vendor/predis/src/Autoloader.php';
            Predis\Autoloader::register();
        }

        $parameters = [
            'scheme' => 'tcp',
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => (int) ($config['port'] ?? 6379),
            'database' => (int) ($config['database'] ?? 0),
        ];

        $password = (string) ($config['password'] ?? '');
        if ($password !== '') {
            $parameters['password'] = $password;
        }

        $this->client = new Predis\Client($parameters);
        $this->client->connect();
    }

    public function get(string $key)
    {
        $value = $this->client->get($key);
        return $value === null ? null : (string) $value;
    }

    public function increment(string $key): int
    {
        return (int) $this->client->incrby($key, 1);
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        if ($ttl > 0) {
            $this->client->setex($key, $ttl, $value);
        } else {
            $this->client->set($key, $value);
        }
        return true;
    }

    public function delete(string $key): bool
    {
        $this->client->del([$key]);
        return true;
    }

    public function flush(): bool
    {
        // Prefix-scoped deletion is handled by TriRedisCache_Cache.
        // Never flush a database shared by other sites.
        return false;
    }

    public function deleteByPrefix(string $prefix): bool
    {
        $cursor = '0';
        $pattern = $prefix . ':*';
        do {
            [$cursor, $keys] = $this->client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            if (!empty($keys)) {
                $this->client->del($keys);
            }
        } while ($cursor !== '0' && $cursor !== 0);
        return true;
    }

    public function info(): array
    {
        $info = $this->client->info();
        $server = $info['Server'] ?? $info;
        $memory = $info['Memory'] ?? $info;
        $keyspace = $info['Keyspace'] ?? $info;
        return [
            'client' => 'predis',
            'connected' => true,
            'version' => (string) ($server['redis_version'] ?? ''),
            'used_memory_human' => (string) ($memory['used_memory_human'] ?? ''),
            'db_keys' => (int) ($keyspace['db0']['keys'] ?? 0),
        ];
    }

    public function details(): array
    {
        $info = $this->client->info();
        return [
            'server' => $info['Server'] ?? [],
            'memory' => $info['Memory'] ?? [],
            'keyspace' => $info['Keyspace'] ?? [],
            'stats' => $info['Stats'] ?? [],
            'clients' => $info['Clients'] ?? [],
        ];
    }

    public function scanKeys(string $pattern, int $limit): array
    {
        $cursor = '0';
        $keys = [];
        do {
            [$cursor, $batch] = $this->client->scan($cursor, [
                'MATCH' => $pattern,
                'COUNT' => 100,
            ]);
            foreach ((array) $batch as $key) {
                $keys[] = (string) $key;
                if (count($keys) >= $limit) {
                    return $keys;
                }
            }
        } while ($cursor !== '0' && $cursor !== 0);
        return $keys;
    }

    public function keyInfo(string $key): array
    {
        $type = (string) $this->client->type($key);
        $ttl = (int) $this->client->ttl($key);
        $size = 0;
        if ($type === 'string') {
            $size = strlen((string) $this->client->get($key));
        }
        return [
            'key' => $key,
            'type' => $type,
            'ttl' => $ttl,
            'size' => $size,
        ];
    }
}
