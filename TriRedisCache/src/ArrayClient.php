<?php

declare(strict_types=1);

final class TriRedisCache_ArrayClient implements TriRedisCache_ClientInterface
{
    /** @var array<string,string> */
    private array $values = [];

    public string $lastKey = '';

    public int $lastTtl = 0;

    public function get(string $key)
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : null;
    }

    public function increment(string $key): int
    {
        $this->values[$key] = (string) (((int) ($this->values[$key] ?? 0)) + 1);
        return (int) $this->values[$key];
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        $this->lastKey = $key;
        $this->lastTtl = $ttl;
        $this->values[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        if (!array_key_exists($key, $this->values)) {
            return false;
        }
        unset($this->values[$key]);
        return true;
    }

    public function flush(): bool
    {
        $this->values = [];
        return true;
    }

    public function deleteByPrefix(string $prefix): bool
    {
        $prefix .= ':';
        foreach (array_keys($this->values) as $key) {
            if (strpos($key, $prefix) === 0) {
                unset($this->values[$key]);
            }
        }
        return true;
    }

    public function info(): array
    {
        return [
            'client' => 'array',
            'connected' => true,
            'keys' => count($this->values),
        ];
    }

    public function details(): array
    {
        return [
            'server' => ['redis_version' => 'array'],
            'memory' => ['used_memory_human' => count($this->values) . ' keys'],
            'keyspace' => ['db0' => ['keys' => count($this->values)]],
            'stats' => [],
            'clients' => [],
        ];
    }

    public function scanKeys(string $pattern, int $limit): array
    {
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        $keys = [];
        foreach (array_keys($this->values) as $key) {
            if (preg_match($regex, $key)) {
                $keys[] = $key;
                if (count($keys) >= $limit) {
                    break;
                }
            }
        }
        return $keys;
    }

    public function keyInfo(string $key): array
    {
        return [
            'key' => $key,
            'type' => 'string',
            'ttl' => -1,
            'size' => isset($this->values[$key]) ? strlen($this->values[$key]) : 0,
        ];
    }
}
