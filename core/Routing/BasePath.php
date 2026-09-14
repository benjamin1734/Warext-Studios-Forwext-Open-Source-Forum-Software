<?php

declare(strict_types=1);

namespace Forwext\Core\Routing;

final readonly class BasePath
{
    private string $value;

    public function __construct(string $basePath = '')
    {
        $this->value = self::normalize($basePath);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function strip(string $requestPath): ?string
    {
        if ($requestPath === '' || $requestPath[0] !== '/') {
            throw new RoutingException('Request path must be absolute.');
        }

        if ($this->value === '') {
            return $requestPath;
        }

        if ($requestPath === $this->value) {
            return '/';
        }

        $prefix = $this->value . '/';
        if (!str_starts_with($requestPath, $prefix)) {
            return null;
        }

        return substr($requestPath, strlen($this->value));
    }

    public function prepend(string $routePath): string
    {
        if ($routePath === '' || $routePath[0] !== '/') {
            throw new RoutingException('Route path must be absolute.');
        }

        if ($this->value === '') {
            return $routePath;
        }

        return $routePath === '/' ? $this->value . '/' : $this->value . $routePath;
    }

    private static function normalize(string $basePath): string
    {
        $basePath = trim($basePath);

        if ($basePath === '' || $basePath === '/') {
            return '';
        }

        if ($basePath[0] !== '/' || str_contains($basePath, '?') || str_contains($basePath, '#')) {
            throw new RoutingException('Base path must be an absolute URL path without query or fragment.');
        }

        if (str_contains($basePath, "\0") || str_contains($basePath, "\r") || str_contains($basePath, "\n")) {
            throw new RoutingException('Base path contains control characters.');
        }

        $segments = explode('/', trim($basePath, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RoutingException('Base path contains an ambiguous segment.');
            }
        }

        return '/' . implode('/', $segments);
    }
}
