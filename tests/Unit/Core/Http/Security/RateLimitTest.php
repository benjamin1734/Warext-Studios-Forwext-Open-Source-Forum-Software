<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http\Security;

use Forwext\Core\Http\Canonical\CanonicalUrlMiddleware;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\RateLimit\FileRateLimitStore;
use Forwext\Core\Http\Security\RateLimit\InMemoryRateLimitStore;
use Forwext\Core\Http\Security\RateLimit\RateLimitMiddleware;
use Forwext\Core\Http\Security\RateLimit\RateLimitPolicy;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-rate-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testFixedWindowResetsAtConfiguredBoundary(): void
    {
        $store = new InMemoryRateLimitStore();
        $policy = new RateLimitPolicy('login', 2, 60);

        self::assertTrue($store->consume('client-a', $policy, 1_000)->allowed);
        self::assertTrue($store->consume('client-a', $policy, 1_001)->allowed);
        $blocked = $store->consume('client-a', $policy, 1_002);
        self::assertFalse($blocked->allowed);
        self::assertSame(1_060, $blocked->resetAt);
        self::assertTrue($store->consume('client-a', $policy, 1_060)->allowed);
    }

    public function testFileStorePersistsCounterAcrossStoreInstances(): void
    {
        $policy = new RateLimitPolicy('api', 1, 60);
        $first = new FileRateLimitStore($this->directory);
        self::assertTrue($first->consume('203.0.113.10', $policy, 2_000)->allowed);

        $second = new FileRateLimitStore($this->directory);
        self::assertFalse($second->consume('203.0.113.10', $policy, 2_001)->allowed);
    }

    public function testMiddlewareUsesResolvedClientIpAndReturns429Headers(): void
    {
        $middleware = new RateLimitMiddleware(
            new InMemoryRateLimitStore(),
            new RateLimitPolicy('web', 1, 60),
        );
        $terminal = new CallableRequestHandler(static fn (Request $_request): Response => Response::text('ok'));
        $request = (new Request(HttpMethod::Get, '/', server: ['REMOTE_ADDR' => '198.51.100.9']))
            ->withAttribute(CanonicalUrlMiddleware::ATTRIBUTE_CLIENT_IP, '203.0.113.25');

        self::assertSame(200, $middleware->process($request, $terminal)->status());
        $blocked = $middleware->process($request, $terminal);
        self::assertSame(429, $blocked->status());
        self::assertNotNull($blocked->headers()->first('Retry-After'));
        self::assertSame('0', $blocked->headers()->first('X-RateLimit-Remaining'));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $target = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($target) && !is_link($target)) {
                $this->removeTree($target);
            } else {
                @unlink($target);
            }
        }

        @rmdir($path);
    }
}
