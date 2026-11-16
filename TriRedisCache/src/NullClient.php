<?php

declare(strict_types=1);

final class TriRedisCache_NullClient implements TriRedisCache_ClientInterface
{
    public function get(string $key)
    {
        return null;
    }

    public function increment(string $key): int
    {
        return 0;
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        return false;
    }

    public function delete(string $key): bool
    {
        return false;
    }

    public function flush(): bool
    {
        return false;
    }

    public function deleteByPrefix(string $prefix): bool
    {
        return false;
    }

    public function info(): array
    {
        return [
            'client' => 'null',
            'connected' => false,
        ];
    }

    public function details(): array
    {
        return [
            'server' => [],
            'memory' => [],
            'keyspace' => [],
            'stats' => [],
            'clients' => [],
        ];
    }

    public function scanKeys(string $pattern, int $limit): array
    {
        return [];
    }

    public function keyInfo(string $key): array
    {
        return [
            'key' => $key,
            'type' => 'none',
            'ttl' => -2,
            'size' => 0,
        ];
    }
}
