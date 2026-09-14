<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http\Canonical;

use Forwext\Core\Http\Canonical\CanonicalUrl;
use Forwext\Core\Http\Canonical\CanonicalUrlMiddleware;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Proxy\CidrSet;
use Forwext\Core\Http\Proxy\TrustedProxyResolver;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use PHPUnit\Framework\TestCase;

final class CanonicalUrlMiddlewareTest extends TestCase
{
    public function testDirectHttpRequestRedirectsOnceToConfiguredHttpsOrigin(): void
    {
        $middleware = new CanonicalUrlMiddleware(
            new CanonicalUrl('https://forum.example.com/community'),
            new TrustedProxyResolver(),
        );
        $terminal = new CallableRequestHandler(static fn (Request $_request): Response => Response::text('ok'));
        $request = new Request(
            HttpMethod::Get,
            '/community/konu/test?page=2',
            new HeaderBag(['Host' => 'forum.example.com']),
            server: ['REMOTE_ADDR' => '203.0.113.20', 'SERVER_PORT' => '80'],
        );

        $response = $middleware->process($request, $terminal);

        self::assertSame(308, $response->status());
        self::assertSame(
            'https://forum.example.com/community/konu/test?page=2',
            $response->headers()->first('location'),
        );
    }

    public function testTrustedTlsTerminatingProxyDoesNotCauseRedirectLoop(): void
    {
        $middleware = new CanonicalUrlMiddleware(
            new CanonicalUrl('https://forum.example.com/community'),
            new TrustedProxyResolver(new CidrSet(['10.0.0.0/8'])),
        );
        $terminal = new CallableRequestHandler(static function (Request $request): Response {
            return Response::json([
                'scheme' => $request->attribute(CanonicalUrlMiddleware::ATTRIBUTE_SCHEME),
                'client_ip' => $request->attribute(CanonicalUrlMiddleware::ATTRIBUTE_CLIENT_IP),
            ]);
        });
        $request = new Request(
            HttpMethod::Get,
            '/community/',
            new HeaderBag([
                'Host' => 'internal.local',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'forum.example.com',
                'X-Forwarded-For' => '203.0.113.77',
            ]),
            server: ['REMOTE_ADDR' => '10.0.0.8', 'SERVER_PORT' => '8080'],
        );

        $response = $middleware->process($request, $terminal);

        self::assertSame(200, $response->status());
        self::assertSame('{"scheme":"https","client_ip":"203.0.113.77"}', $response->body());
    }

    public function testUntrustedForwardingHeaderCannotCreateHttpsRedirectLoop(): void
    {
        $middleware = new CanonicalUrlMiddleware(
            new CanonicalUrl('https://forum.example.com/community'),
            new TrustedProxyResolver(),
        );
        $terminal = new CallableRequestHandler(
            static fn (Request $_request): Response => Response::text('should-not-run'),
        );
        $request = new Request(
            HttpMethod::Get,
            '/community/',
            new HeaderBag([
                'Host' => 'internal.local',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'forum.example.com',
            ]),
            server: ['REMOTE_ADDR' => '198.51.100.50', 'SERVER_PORT' => '80'],
        );

        $response = $middleware->process($request, $terminal);

        self::assertSame(400, $response->status());
        self::assertNull($response->headers()->first('location'));
    }
}
