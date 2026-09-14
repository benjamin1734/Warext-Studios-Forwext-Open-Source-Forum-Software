<?php

declare(strict_types=1);

namespace Forwext\Core\Routing;

use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;

final readonly class Route
{
    /** @var list<HttpMethod> */
    private array $methods;

    /** @var list<MiddlewareInterface> */
    private array $middleware;

    /**
     * @param non-empty-list<HttpMethod> $methods
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private string $name,
        array $methods,
        private PathTemplate $path,
        private RequestHandlerInterface $handler,
        array $middleware = [],
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $name) !== 1) {
            throw new RoutingException('Route name is invalid.');
        }

        if ($methods === []) {
            throw new RoutingException('Route must accept at least one HTTP method.');
        }

        $deduplicated = [];
        foreach ($methods as $method) {
            $deduplicated[$method->value] = $method;
        }
        $this->methods = array_values($deduplicated);

        foreach ($middleware as $entry) {
            if (!$entry instanceof MiddlewareInterface) {
                throw new RoutingException('Route middleware contains an invalid entry.');
            }
        }
        $this->middleware = $middleware;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<HttpMethod> */
    public function methods(): array
    {
        return $this->methods;
    }

    public function path(): PathTemplate
    {
        return $this->path;
    }

    public function handler(): RequestHandlerInterface
    {
        return $this->handler;
    }

    /** @return list<MiddlewareInterface> */
    public function middleware(): array
    {
        return $this->middleware;
    }

    public function accepts(HttpMethod $method): bool
    {
        foreach ($this->methods as $accepted) {
            if ($accepted === $method || ($method === HttpMethod::Head && $accepted === HttpMethod::Get)) {
                return true;
            }
        }

        return false;
    }
}
