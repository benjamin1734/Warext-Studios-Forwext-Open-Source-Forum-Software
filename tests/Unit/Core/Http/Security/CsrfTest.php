<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http\Security;

use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Http\Security\Csrf\CsrfTokenManager;
use Forwext\Core\Security\Secret\SecretKey;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenIsBoundToContextScopeAndLifetime(): void
    {
        $manager = new CsrfTokenManager(SecretKey::generate(), ttlSeconds: 120, futureSkewSeconds: 10);
        $context = str_repeat('a', 64);
        $token = $manager->issue($context, 'member', now: 1_000);

        self::assertTrue($manager->verify($token, $context, 'member', now: 1_100));
        self::assertFalse($manager->verify($token, str_repeat('b', 64), 'member', now: 1_100));
        self::assertFalse($manager->verify($token, $context, 'admin', now: 1_100));
        self::assertFalse($manager->verify($token, $context, 'member', now: 1_121));
    }

    public function testMiddlewareIssuesContextAndValidatesUnsafeRequest(): void
    {
        $middleware = new CsrfMiddleware(new CsrfTokenManager(SecretKey::generate()));
        $terminal = new CallableRequestHandler(static function (Request $request): Response {
            return Response::text((string) $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN, 'accepted'));
        });

        $getResponse = $middleware->process(new Request(HttpMethod::Get, '/form'), $terminal);
        $token = $getResponse->body();
        $setCookie = $getResponse->headers()->first('Set-Cookie');
        self::assertNotNull($setCookie);
        self::assertMatchesRegularExpression('/^v1\.[0-9]+\.[a-f0-9]{32}\.[a-f0-9]{64}$/', $token);
        self::assertSame(1, preg_match('/__Host-forwext_csrf=([a-f0-9]{64})/', $setCookie, $matches));
        self::assertArrayHasKey(1, $matches);
        $context = (string) $matches[1];

        $post = new Request(
            HttpMethod::Post,
            '/form',
            new HeaderBag(['X-CSRF-Token' => $token]),
            cookies: ['__Host-forwext_csrf' => $context],
        );
        self::assertSame(200, $middleware->process($post, $terminal)->status());

        $invalid = new Request(
            HttpMethod::Post,
            '/form',
            new HeaderBag(['X-CSRF-Token' => $token . 'tampered']),
            cookies: ['__Host-forwext_csrf' => $context],
        );
        self::assertSame(403, $middleware->process($invalid, $terminal)->status());
    }
}
