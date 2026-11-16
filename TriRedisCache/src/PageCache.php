<?php

declare(strict_types=1);

final class TriRedisCache_PageCache
{
    private const SCHEMA_VERSION = 'v1';

    private const CACHEABLE_ARCHIVES = [
        'index',
        'front',
        'single',
        'post',
        'page',
        'attachment',
        'category',
        'tag',
        'author',
        'date',
    ];

    public static function makeKey(string $origin, string $path, string $context = ''): string
    {
        return self::SCHEMA_VERSION . '-' . hash('sha256', $origin . '|' . $path . '|' . $context);
    }

    public static function requestPath(array $server): ?string
    {
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['path'])) {
            return null;
        }

        $path = rawurldecode($parts['path']);
        if ($path === '' || $path[0] !== '/' || str_contains($path, '//')) {
            return null;
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public static function canonicalOrigin(array $server, string $siteOrigin): ?string
    {
        $site = strtolower(rtrim(trim($siteOrigin), '/'));
        $siteParts = parse_url($site);
        if (!isset($siteParts['scheme'], $siteParts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($server['REQUEST_SCHEME'] ?? ''));
        if ($scheme === '') {
            $https = strtolower((string) ($server['HTTPS'] ?? ''));
            $scheme = $https === 'on' || $https === '1' ? 'https' : 'http';
        }

        $host = strtolower((string) ($server['HTTP_HOST'] ?? ''));
        $origin = $scheme . '://' . $host;
        return strcasecmp($origin, $site) === 0 ? $site : null;
    }

    public static function isCacheableRequest(array $server, array $cookies, string $cookiePrefix = ''): bool
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            return false;
        }

        $query = (string) ($server['QUERY_STRING'] ?? '');
        if ($query !== '') {
            return false;
        }

        $path = self::requestPath($server);
        if ($path === null || !self::isCacheablePath($path)) {
            return false;
        }

        foreach (array_keys($cookies) as $rawName) {
            $name = (string) $rawName;
            if ($cookiePrefix !== '' && str_starts_with($name, $cookiePrefix)) {
                $name = substr($name, strlen($cookiePrefix));
            }

            if (
                str_starts_with($name, '__typecho_remember_')
                || $name === '__typecho_unapproved_comment'
                || str_starts_with($name, 'protectPassword_')
            ) {
                return false;
            }
        }

        return true;
    }

    public static function isCacheableArchive(string $archiveType, bool $hidden = false, string $password = ''): bool
    {
        if ($hidden || $password !== '') {
            return false;
        }

        return in_array($archiveType, self::CACHEABLE_ARCHIVES, true);
    }

    public static function isCacheableResponse(
        array $state,
        array $headers,
        int $httpCode,
        array $cookiesAtStart,
        array $cookiesAtEnd,
        string $expectedContentType = 'text/html'
    ): bool {
        if ($httpCode !== 200 || (int) ($state['status'] ?? 200) !== 200) {
            return false;
        }

        $expected = strtolower(trim($expectedContentType));
        $contentType = strtolower(trim((string) ($state['contentType'] ?? $expected)));
        if ($contentType !== $expected) {
            return false;
        }

        foreach ((array) ($state['headers'] ?? []) as $name => $value) {
            if (strcasecmp((string) $name, 'Location') === 0) {
                return false;
            }
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                $headerType = strtolower(trim(explode(';', (string) $value, 2)[0]));
                if ($headerType !== $expected) {
                    return false;
                }
            }
        }

        if (!empty($state['cookies'])) {
            return false;
        }

        foreach ($headers as $header) {
            $header = (string) $header;
            if (stripos($header, 'Location:') === 0 || stripos($header, 'Set-Cookie:') === 0) {
                return false;
            }
        }

        return $cookiesAtStart === $cookiesAtEnd;
    }

    private static function isCacheablePath(string $path): bool
    {
        $excluded = [
            '/admin',
            '/api',
            '/action',
            '/feed',
            '/install',
            '/login',
            '/register',
            '/search',
            '/sitemap',
            '/xmlrpc',
        ];

        foreach ($excluded as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }

        return true;
    }
}
