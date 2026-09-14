<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http\Security;

use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Cors\CorsException;
use Forwext\Core\Http\Security\Cors\CorsMiddleware;
use Forwext\Core\Http\Security\Cors\CorsPolicy;
use PHPUnit\Framework\TestCase;

final class CorsTest extends TestCase
{
    public function testAllowedPreflightReturnsExplicitPolicyHeaders(): void
    {
        $policy = new CorsPolicy(
            ['https://app.example.com'],
            [HttpMethod::Get, HttpMethod::Post],
            ['Content-Type', 'X-CSRF-Token'],
            allowCredentials: true,
        );
        $middleware = new CorsMiddleware($policy);
        $terminal = new CallableRequestHandler(static fn (Request $_request): Response => Response::text('unexpected'));
        $request = new Request(
            HttpMethod::Options,
            '/api',
            new HeaderBag([
                'Origin' => 'https://app.example.com',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'Content-Type, X-CSRF-Token',
            ]),
        );

        $response = $middleware->process($request, $terminal);

        self::assertSame(204, $response->status());
        self::assertSame('https://app.example.com', $response->headers()->first('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers()->first('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('POST', (string) $response->headers()->first('Access-Control-Allow-Methods'));
    }

    public function testDisallowedOriginIsRejected(): void
    {
        $middleware = new CorsMiddleware(new CorsPolicy(['https://allowed.example.com']));
        $terminal = new CallableRequestHandler(static fn (Request $_request): Response => Response::text('unexpected'));
        $request = new Request(
            HttpMethod::Get,
            '/api',
            new HeaderBag(['Origin' => 'https://evil.example.com']),
        );

        self::assertSame(403, $middleware->process($request, $terminal)->status());
    }

    public function testCredentialedWildcardPolicyIsRejected(): void
    {
        $this->expectException(CorsException::class);
        new CorsPolicy(['*'], allowCredentials: true);
    }
}
