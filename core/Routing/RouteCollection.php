<?php

declare(strict_types=1);

namespace Forwext\Core\Routing;

use Forwext\Core\Http\HttpMethod;

final class RouteCollection
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, Route> */
    private array $byName = [];

    public function add(Route $route): void
    {
        if (isset($this->byName[$route->name()])) {
            throw new RoutingException(sprintf('Route name "%s" is already registered.', $route->name()));
        }

        foreach ($this->routes as $existing) {
            if ($existing->path()->template() !== $route->path()->template()) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($existing->accepts($method) || ($method === HttpMethod::Get && $existing->accepts(HttpMethod::Head))) {
                    throw new RoutingException(sprintf(
                        'Route "%s" conflicts with "%s" for %s %s.',
                        $route->name(),
                        $existing->name(),
                        $method->value,
                        $route->path()->template(),
                    ));
                }
            }
        }

        $this->routes[] = $route;
        $this->byName[$route->name()] = $route;
    }

    public function get(string $name): Route
    {
        return $this->byName[$name] ?? throw new RoutingException(sprintf('Unknown route "%s".', $name));
    }

    /** @return list<Route> */
    public function all(): array
    {
        return $this->routes;
    }

    public function resolve(HttpMethod $method, string $path): RouteResolution
    {
        /** @var list<RouteMatch> $methodMatches */
        $methodMatches = [];
        /** @var array<string, HttpMethod> $allowed */
        $allowed = [];

        foreach ($this->routes as $route) {
            $parameters = $route->path()->match($path);
            if ($parameters === null) {
                continue;
            }

            foreach ($route->methods() as $accepted) {
                $allowed[$accepted->value] = $accepted;
                if ($accepted === HttpMethod::Get) {
                    $allowed[HttpMethod::Head->value] = HttpMethod::Head;
                }
            }

            if ($route->accepts($method)) {
                $methodMatches[] = new RouteMatch($route, $parameters);
            }
        }

        if ($methodMatches === []) {
            return new RouteResolution(null, array_values($allowed));
        }

        usort(
            $methodMatches,
            static fn (RouteMatch $left, RouteMatch $right): int =>
                $right->route->path()->specificity() <=> $left->route->path()->specificity(),
        );

        if (
            isset($methodMatches[1])
            && $methodMatches[0]->route->path()->specificity() === $methodMatches[1]->route->path()->specificity()
        ) {
            throw new RoutingException(sprintf(
                'Ambiguous route match between "%s" and "%s" for path "%s".',
                $methodMatches[0]->route->name(),
                $methodMatches[1]->route->name(),
                $path,
            ));
        }

        return new RouteResolution($methodMatches[0], array_values($allowed));
    }
}
