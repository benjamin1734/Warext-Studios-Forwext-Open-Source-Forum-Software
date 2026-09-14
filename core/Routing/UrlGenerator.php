<?php

declare(strict_types=1);

namespace Forwext\Core\Routing;

use Forwext\Core\Http\Canonical\CanonicalUrl;

final readonly class UrlGenerator
{
    public function __construct(
        private RouteCollection $routes,
        private BasePath $basePath = new BasePath(),
        private ?CanonicalUrl $canonicalUrl = null,
    ) {
        if ($canonicalUrl !== null && $canonicalUrl->basePath()->value() !== $basePath->value()) {
            throw new RoutingException('URL generator base path must match the canonical URL base path.');
        }
    }

    /**
     * @param array<string, string|int> $parameters
     * @param array<string, scalar|list<scalar>|null> $query
     */
    public function path(string $routeName, array $parameters = [], array $query = []): string
    {
        $path = $this->basePath->prepend($this->routes->get($routeName)->path()->generate($parameters));
        if ($query === []) {
            return $path;
        }

        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $encoded === '' ? $path : $path . '?' . $encoded;
    }

    /**
     * @param array<string, string|int> $parameters
     * @param array<string, scalar|list<scalar>|null> $query
     */
    public function absolute(string $routeName, array $parameters = [], array $query = []): string
    {
        if ($this->canonicalUrl === null) {
            throw new RoutingException('Cannot generate an absolute URL without a canonical URL configuration.');
        }

        return $this->canonicalUrl->origin() . $this->path($routeName, $parameters, $query);
    }
}
