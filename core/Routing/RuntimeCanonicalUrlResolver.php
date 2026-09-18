<?php

declare(strict_types=1);

namespace Forwext\Core\Routing;

final readonly class RuntimeCanonicalUrlResolver
{
    public static function resolve(string $configuredCanonicalUrl, ?string $scriptName = null): string
    {
        $normalized = rtrim($configuredCanonicalUrl, '/');
        $parts = parse_url($configuredCanonicalUrl);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $normalized;
        }

        $configuredPath = $parts['path'] ?? '';
        if (!is_string($configuredPath)) {
            return $normalized;
        }

        if (rtrim($configuredPath, '/') !== '') {
            return $normalized;
        }

        if ($scriptName === null) {
            $candidate = $_SERVER['SCRIPT_NAME'] ?? null;
            $scriptName = is_string($candidate) ? $candidate : null;
        }

        $basePath = self::scriptBasePath($scriptName);
        return $basePath === '' ? $normalized : $normalized . $basePath;
    }

    public static function scriptBasePath(?string $scriptName): string
    {
        if ($scriptName === null || $scriptName === '') {
            return '';
        }

        if (str_contains($scriptName, "\0")
            || str_contains($scriptName, "\r")
            || str_contains($scriptName, "\n")
        ) {
            return '';
        }

        $path = parse_url($scriptName, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            return '';
        }

        $directory = str_replace('\\', '/', dirname($path));
        if ($directory === '/' || $directory === '.' || $directory === '') {
            return '';
        }

        $segments = explode('/', trim($directory, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
        }

        return '/' . implode('/', $segments);
    }
}
