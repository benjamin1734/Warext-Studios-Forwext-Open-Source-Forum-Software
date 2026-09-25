<?php

declare(strict_types=1);

namespace Forwext\Core\Routing;

use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\MiddlewarePipeline;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class Router implements RequestHandlerInterface
{
    public const ATTRIBUTE_ROUTE_NAME = 'route_name';
    public const ATTRIBUTE_ROUTE_PARAMETERS = 'route_params';

    /** @var list<MiddlewareInterface> */
    private array $globalMiddleware;
    private RoutingErrorResponder $errors;

    /** @param list<MiddlewareInterface> $globalMiddleware */
    public function __construct(
        private RouteCollection $routes,
        private BasePath $basePath = new BasePath(),
        array $globalMiddleware = [],
        ?RoutingErrorResponder $errors = null,
    ) {
        foreach ($globalMiddleware as $entry) {
            if (!$entry instanceof MiddlewareInterface) {
                throw new RoutingException('Router global middleware contains an invalid entry.');
            }
        }
        $this->globalMiddleware = array_values($globalMiddleware);
        $this->errors = $errors ?? new DefaultRoutingErrorResponder();
    }

    public function handle(Request $request): Response
    {
        $path = $this->requestPath($request->uri());
        $relativePath = $this->basePath->strip($path);

        if ($relativePath === null) {
            return $this->errors->notFound($request, null);
        }

        $resolution = $this->routes->resolve($request->method(), $relativePath);
        if ($resolution->match === null) {
            if (!$resolution->pathExists()) {
                return $this->errors->notFound($request, $relativePath);
            }

            $allow = array_map(
                static fn (HttpMethod $method): string => $method->value,
                $resolution->allowedMethods,
            );
            sort($allow, SORT_STRING);

            return $this->errors->methodNotAllowed($request, $relativePath, $allow);
        }

        $match = $resolution->match;
        $routedRequest = $request
            ->withAttribute(self::ATTRIBUTE_ROUTE_NAME, $match->route->name())
            ->withAttribute(self::ATTRIBUTE_ROUTE_PARAMETERS, $match->parameters);

        $handler = $match->route->handler();
        $middleware = array_merge($this->globalMiddleware, $match->route->middleware());
        if ($middleware !== []) {
            $handler = new MiddlewarePipeline($middleware, $handler);
        }

        return $handler->handle($routedRequest);
    }

    private function requestPath(string $uri): string
    {
        if (!str_starts_with($uri, '/')) {
            throw new RoutingException('Only origin-form request targets are supported by the router.');
        }

        $path = explode('?', $uri, 2)[0];
        return $path === '' ? '/' : $path;
    }
}
