<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Routing;

use Forwext\Core\Http\Canonical\CanonicalUrl;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\PathTemplate;
use Forwext\Core\Routing\Route;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Routing\Router;
use Forwext\Core\Routing\RoutingException;
use Forwext\Core\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testNamedFriendlyRouteDispatchesInsideSubfolder(): void
    {
        $routes = new RouteCollection();
        $routes->add(new Route(
            'thread.view',
            [HttpMethod::Get],
            new PathTemplate('/konu/{slug}', ['slug' => '[a-z0-9-]+']),
            new CallableRequestHandler(static function (Request $request): Response {
                $params = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS);
                self::assertIsArray($params);
                return Response::text((string) $params['slug']);
            }),
        ));

        $router = new Router($routes, new BasePath('/community'));
        $response = $router->handle(new Request(HttpMethod::Get, '/community/konu/merhaba-dunya?from=home'));

        self::assertSame(200, $response->status());
        self::assertSame('merhaba-dunya', $response->body());
    }

    public function testWrongMethodReturns405AndAllowHeader(): void
    {
        $routes = new RouteCollection();
        $routes->add(new Route(
            'profile.view',
            [HttpMethod::Get],
            new PathTemplate('/uye/{name}'),
            new CallableRequestHandler(static fn (Request $_request): Response => Response::text('ok')),
        ));

        $response = (new Router($routes))->handle(new Request(HttpMethod::Post, '/uye/benjamin17'));

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->headers()->first('allow'));
    }

    public function testSpecificStaticRouteWinsOverDynamicRoute(): void
    {
        $routes = new RouteCollection();
        $routes->add(new Route(
            'dynamic',
            [HttpMethod::Get],
            new PathTemplate('/uye/{name}'),
            new CallableRequestHandler(static fn (Request $_request): Response => Response::text('dynamic')),
        ));
        $routes->add(new Route(
            'settings',
            [HttpMethod::Get],
            new PathTemplate('/uye/ayarlar'),
            new CallableRequestHandler(static fn (Request $_request): Response => Response::text('static')),
        ));

        $response = (new Router($routes))->handle(new Request(HttpMethod::Get, '/uye/ayarlar'));
        self::assertSame('static', $response->body());
    }

    public function testUrlGeneratorUsesCanonicalOriginAndSubfolder(): void
    {
        $routes = new RouteCollection();
        $routes->add(new Route(
            'thread.view',
            [HttpMethod::Get],
            new PathTemplate('/konu/{slug}', ['slug' => '[a-z0-9-]+']),
            new CallableRequestHandler(static fn (Request $_request): Response => Response::text('ok')),
        ));

        $canonical = new CanonicalUrl('https://forum.example.com/community');
        $generator = new UrlGenerator($routes, new BasePath('/community'), $canonical);

        self::assertSame(
            'https://forum.example.com/community/konu/merhaba-dunya?page=2',
            $generator->absolute('thread.view', ['slug' => 'merhaba-dunya'], ['page' => 2]),
        );
    }

    public function testEncodedSlashAndDotSegmentsCannotBecomeRouteParameters(): void
    {
        $template = new PathTemplate('/dosya/{name}');
        self::assertNull($template->match('/dosya/a%2Fb'));
        self::assertNull($template->match('/dosya/%2E%2E'));
    }

    public function testUnsafeEncodedStaticSegmentIsRejectedAtRegistration(): void
    {
        $this->expectException(RoutingException::class);
        new PathTemplate('/unsafe%2Fsegment');
    }
}
