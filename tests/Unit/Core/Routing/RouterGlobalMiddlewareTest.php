<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Routing;

use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\PathTemplate;
use Forwext\Core\Routing\Route;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouterGlobalMiddlewareTest extends TestCase
{
    public function testGlobalMiddlewareReceivesResolvedRouteAttributes(): void
    {
        $routes = new RouteCollection();
        $routes->add(new Route(
            'demo.route',
            [HttpMethod::Get],
            new PathTemplate('/demo/{id}', ['id'=>'[0-9]+']),
            new CallableRequestHandler(static fn (Request $_): Response => Response::html('<p>ok</p>')),
        ));
        $observer = new RouteAttributeObserverMiddleware();
        $router = new Router($routes, new BasePath(), [$observer]);

        $response = $router->handle(new Request(HttpMethod::Get, '/demo/42'));

        self::assertSame(200, $response->status());
        self::assertSame('demo.route', $observer->routeName);
        self::assertSame(['id'=>'42'], $observer->routeParameters);
    }
}

final class RouteAttributeObserverMiddleware implements MiddlewareInterface
{
    public ?string $routeName = null;
    /** @var array<string,string> */
    public array $routeParameters = [];

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $name = $request->attribute(Router::ATTRIBUTE_ROUTE_NAME);
        $params = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $this->routeName = is_string($name) ? $name : null;
        $this->routeParameters = is_array($params) ? $params : [];
        return $next->handle($request);
    }
}
