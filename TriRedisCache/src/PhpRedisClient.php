<?php

declare(strict_types=1);

final class TriRedisCache_PhpRedisClient implements TriRedisCache_ClientInterface
{
    private Redis $redis;

    public function __construct(array $config)
    {
        if (!class_exists('Redis')) {
            throw new RuntimeException('phpredis extension is not installed.');
        }

        $this->redis = new Redis();
        $connected = $this->redis->connect(
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 6379),
            2.0
        );
        if (!$connected) {
            throw new RuntimeException('Unable to connect to Redis.');
        }

        $password = (string) ($config['password'] ?? '');
        if ($password !== '') {
            $this->redis->auth($password);
        }

        $database = (int) ($config['database'] ?? 0);
        if ($database > 0) {
            $this->redis->select($database);
        }

        $this->redis->ping();
    }

    public function get(string $key)
    {
        $value = $this->redis->get($key);
        return $value === false ? null : (string) $value;
    }

    public function increment(string $key): int
    {
        return (int) $this->redis->incr($key);
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        if ($ttl > 0) {
            return $this->redis->setex($key, $ttl, $value);
        }
        return $this->redis->set($key, $value);
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($key) >= 0;
    }

    public function flush(): bool
    {
        // Prefix-scoped deletion is handled by TriRedisCache_Cache.
        // Never flush a database shared by other sites.
        return false;
    }

    public function deleteByPrefix(string $prefix): bool
    {
        $cursor = 0;
        $pattern = $prefix . ':*';
        do {
            $keys = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            if ($keys === false) {
                break;
            }
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
        } while ((int) $cursor !== 0);
        return true;
    }

    public function info(): array
    {
        $info = $this->redis->info();
        return [
            'client' => 'phpredis',
            'connected' => true,
            'version' => (string) ($info['redis_version'] ?? ''),
            'used_memory_human' => (string) ($info['used_memory_human'] ?? ''),
            'db_keys' => (int) ($info['db0']['keys'] ?? 0),
        ];
    }

    public function details(): array
    {
        $info = $this->redis->info();
        return [
            'server' => $info['Server'] ?? $info,
            'memory' => $info['Memory'] ?? $info,
            'keyspace' => $info['Keyspace'] ?? $info,
            'stats' => $info['Stats'] ?? $info,
            'clients' => $info['Clients'] ?? $info,
        ];
    }

    public function scanKeys(string $pattern, int $limit): array
    {
        $cursor = 0;
        $keys = [];
        do {
            $batch = $this->redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            if ($batch === false) {
                break;
            }
            foreach ($batch as $key) {
                $keys[] = (string) $key;
                if (count($keys) >= $limit) {
                    return $keys;
                }
            }
        } while ((int) $cursor !== 0);
        return $keys;
    }

    public function keyInfo(string $key): array
    {
        $type = (string) $this->redis->type($key);
        $ttl = (int) $this->redis->ttl($key);
        $size = 0;
        if ($type === 'string') {
            $size = (int) $this->redis->strlen($key);
        }
        return [
            'key' => $key,
            'type' => $type,
            'ttl' => $ttl,
            'size' => $size,
        ];
    }
}
