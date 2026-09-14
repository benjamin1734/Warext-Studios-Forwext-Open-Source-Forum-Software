<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http\Proxy;

use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Proxy\CidrSet;
use Forwext\Core\Http\Proxy\TrustedProxyResolver;
use Forwext\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class TrustedProxyResolverTest extends TestCase
{
    public function testCidrSetSupportsIpv4AndIpv6(): void
    {
        $set = new CidrSet(['10.0.0.0/8', '2001:db8::/32']);

        self::assertTrue($set->contains('10.20.30.40'));
        self::assertFalse($set->contains('11.20.30.40'));
        self::assertTrue($set->contains('2001:db8::1234'));
        self::assertFalse($set->contains('2001:db9::1'));
    }

    public function testUntrustedForwardingHeadersAreNotUsed(): void
    {
        $request = new Request(
            HttpMethod::Get,
            '/',
            new HeaderBag([
                'Host' => 'internal.example.test:8080',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'forum.example.com',
                'X-Forwarded-For' => '203.0.113.50',
            ]),
            server: ['REMOTE_ADDR' => '198.51.100.20', 'SERVER_PORT' => '8080'],
        );

        $context = (new TrustedProxyResolver())->resolve($request);

        self::assertSame('http', $context->scheme);
        self::assertSame('internal.example.test', $context->host);
        self::assertSame(8080, $context->port);
        self::assertSame('198.51.100.20', $context->clientIp);
        self::assertTrue($context->untrustedForwardingHeadersPresent);
    }

    public function testTrustedProxyResolvesExternalSchemeHostAndClientChain(): void
    {
        $resolver = new TrustedProxyResolver(new CidrSet(['10.0.0.0/8']));
        $request = new Request(
            HttpMethod::Get,
            '/',
            new HeaderBag([
                'Host' => 'internal.local:8080',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'forum.example.com',
                'X-Forwarded-For' => '203.0.113.9, 10.1.2.2',
            ]),
            server: ['REMOTE_ADDR' => '10.1.2.3', 'SERVER_PORT' => '8080'],
        );

        $context = $resolver->resolve($request);

        self::assertSame('https', $context->scheme);
        self::assertSame('forum.example.com', $context->host);
        self::assertSame(443, $context->port);
        self::assertSame('203.0.113.9', $context->clientIp);
        self::assertTrue($context->trustedProxy);
    }

    public function testRfcForwardedHeaderIsResolvedFromTrustedProxy(): void
    {
        $resolver = new TrustedProxyResolver(new CidrSet(['10.0.0.0/8']));
        $request = new Request(
            HttpMethod::Get,
            '/',
            new HeaderBag([
                'Host' => 'internal.local:8080',
                'Forwarded' => 'for=203.0.113.19;proto=https;host=forum.example.com',
            ]),
            server: ['REMOTE_ADDR' => '10.1.2.3', 'SERVER_PORT' => '8080'],
        );

        $context = $resolver->resolve($request);

        self::assertSame('https', $context->scheme);
        self::assertSame('forum.example.com', $context->host);
        self::assertSame(443, $context->port);
        self::assertSame('203.0.113.19', $context->clientIp);
    }

    public function testCloudflareHeadersAreUsedOnlyFromConfiguredCloudflareRange(): void
    {
        $resolver = new TrustedProxyResolver(
            cloudflareProxies: new CidrSet(['192.0.2.0/24']),
        );
        $request = new Request(
            HttpMethod::Get,
            '/',
            new HeaderBag([
                'Host' => 'forum.example.com',
                'CF-Connecting-IP' => '203.0.113.44',
                'CF-Visitor' => '{"scheme":"https"}',
            ]),
            server: ['REMOTE_ADDR' => '192.0.2.10', 'SERVER_PORT' => '80'],
        );

        $context = $resolver->resolve($request);

        self::assertSame('203.0.113.44', $context->clientIp);
        self::assertSame('https', $context->scheme);
        self::assertTrue($context->cloudflareProxy);
        self::assertSame(443, $context->port);
    }
}
