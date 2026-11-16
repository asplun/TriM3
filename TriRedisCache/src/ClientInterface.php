<?php

declare(strict_types=1);

interface TriRedisCache_ClientInterface
{
    public function get(string $key);

    public function set(string $key, string $value, int $ttl): bool;

    public function increment(string $key): int;

    public function delete(string $key): bool;

    public function flush(): bool;

    public function deleteByPrefix(string $prefix): bool;

    public function info(): array;

    public function details(): array;

    public function scanKeys(string $pattern, int $limit): array;

    public function keyInfo(string $key): array;
}
