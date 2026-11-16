<?php

declare(strict_types=1);

final class TriRedisCache_Cache
{
    private TriRedisCache_ClientInterface $client;

    private string $prefix;

    private int $defaultTtl;

    private int $hits = 0;

    private int $misses = 0;

    private string $statsPrefix = "meta:stats";

    public function __construct(TriRedisCache_ClientInterface $client, array $config = [])
    {
        $this->client = $client;
        $this->prefix = $this->normalizePrefix((string) ($config['prefix'] ?? 'tri'));
        $this->defaultTtl = $this->normalizeTtl($config['default_ttl'] ?? 300, 300);
    }

    public function get(string $key, string $group = 'default')
    {
        try {
            $stored = $this->client->get($this->namespacedKey($key, $group));
        } catch (Throwable $e) {
            $this->recordStat("misses");
            $this->misses++;
            return null;
        }

        if ($stored === null) {
            $this->recordStat("misses");
            $this->misses++;
            return null;
        }

        $value = @unserialize($stored, ['allowed_classes' => true]);
        if ($value === false && $stored !== serialize(false)) {
            $this->recordStat("misses");
            $this->misses++;
            return null;
        }

        $this->recordStat("hits");
        $this->hits++;
        return $value;
    }

    public function set(string $key, $value, string $group = 'default', ?int $ttl = null): bool
    {
        try {
            $stored = serialize($value);
            return $this->client->set(
                $this->namespacedKey($key, $group),
                $stored,
                $this->normalizeTtl($ttl, $this->defaultTtl)
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    public function delete(string $key, string $group = 'default'): bool
    {
        try {
            return $this->client->delete($this->namespacedKey($key, $group));
        } catch (Throwable $e) {
            return false;
        }
    }

    public function flush(): bool
    {
        try {
            return $this->client->deleteByPrefix($this->prefix);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function deleteGroup(string $group): bool
    {
        try {
            return $this->client->deleteByPrefix($this->prefix . ':' . $this->slug($group));
        } catch (Throwable $e) {
            return false;
        }
    }

    public function status(): array
    {
        return [
            'hits' => $this->persistentStat("hits"),
            'misses' => $this->persistentStat("misses"),
            'prefix' => $this->prefix,
            'default_ttl' => $this->defaultTtl,
            'client' => $this->client->info(),
        ];
    }

    private function recordStat(string $name): void
    {
        try {
            $this->client->increment($this->prefix . ':' . $this->statsPrefix . ':' . $name);
        } catch (Throwable $e) {
        }
    }

    private function persistentStat(string $name): int
    {
        try {
            return max(0, (int) $this->client->get($this->prefix . ':' . $this->statsPrefix . ':' . $name));
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function client(): TriRedisCache_ClientInterface
    {
        return $this->client;
    }

    public function getStats(): array
    {
        return [
            'hits' => $this->persistentStat("hits"),
            'misses' => $this->persistentStat("misses"),
        ];
    }

    private function namespacedKey(string $key, string $group): string
    {
        return $this->prefix . ':' . $this->slug($group) . ':' . $this->keySlug($key);
    }

    private function keySlug(string $key): string
    {
        $slug = $this->slug($key);
        if (strlen($slug) > 150) {
            $slug = substr($slug, 0, 100) . '-' . md5($key);
        }
        return $slug;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9_.-]+/', '-', $slug) ?? '';
        return trim($slug, '-.') ?: 'default';
    }

    private function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return 'tri';
        }
        return trim(preg_replace('/[^A-Za-z0-9_.-]+/', '-', $prefix) ?? 'tri', '-.');
    }

    private function normalizeTtl($ttl, int $fallback): int
    {
        if ($ttl === null) {
            return max(0, $fallback);
        }
        $ttl = (int) $ttl;
        return $ttl < 0 ? 0 : $ttl;
    }
}
