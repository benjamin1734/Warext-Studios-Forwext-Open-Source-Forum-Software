<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http\Security;

use Forwext\Core\Http\Canonical\CanonicalUrlMiddleware;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Headers\SecurityHeadersMiddleware;
use Forwext\Core\Http\Security\TrustedHost\TrustedHostMiddleware;
use Forwext\Core\Http\Security\TrustedHost\TrustedHostPolicy;
use PHPUnit\Framework\TestCase;

final class TrustedHostAndHeadersTest extends TestCase
{
    public function testTrustedHostSupportsExactAndSubdomainPattern(): void
    {
        $policy = new TrustedHostPolicy(['forum.example.com', '*.community.example.com']);

        self::assertTrue($policy->allows('forum.example.com'));
        self::assertTrue($policy->allows('eu.community.example.com'));
        self::assertFalse($policy->allows('community.example.com'));
        self::assertFalse($policy->allows('evil-example.com'));
    }

    public function testUntrustedEffectiveHostIsRejected(): void
    {
        $middleware = new TrustedHostMiddleware(new TrustedHostPolicy(['forum.example.com']));
        $terminal = new CallableRequestHandler(static fn (Request $_request): Response => Response::text('ok'));
        $request = (new Request(HttpMethod::Get, '/'))
            ->withAttribute(CanonicalUrlMiddleware::ATTRIBUTE_HOST, 'evil.example.com');

        self::assertSame(421, $middleware->process($request, $terminal)->status());
    }

    public function testSecurityHeadersAndHstsAreAppliedForHttps(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $terminal = new CallableRequestHandler(static fn (Request $_request): Response => Response::html('ok'));
        $request = (new Request(HttpMethod::Get, '/'))
            ->withAttribute(CanonicalUrlMiddleware::ATTRIBUTE_SCHEME, 'https');

        $response = $middleware->process($request, $terminal);

        self::assertSame('nosniff', $response->headers()->first('X-Content-Type-Options'));
        self::assertNotNull($response->headers()->first('Content-Security-Policy'));
        self::assertSame('max-age=31536000', $response->headers()->first('Strict-Transport-Security'));
    }

    public function testHstsIsNotEmittedForPlainHttp(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $terminal = new CallableRequestHandler(static fn (Request $_request): Response => Response::text('ok'));
        $request = (new Request(HttpMethod::Get, '/'))
            ->withAttribute(CanonicalUrlMiddleware::ATTRIBUTE_SCHEME, 'http');

        self::assertNull($middleware->process($request, $terminal)->headers()->first('Strict-Transport-Security'));
    }
}
