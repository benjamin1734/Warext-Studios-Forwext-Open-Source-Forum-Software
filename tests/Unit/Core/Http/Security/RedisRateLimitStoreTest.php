<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http\Security;

use Forwext\Core\Http\Security\RateLimit\RateLimitException;
use Forwext\Core\Http\Security\RateLimit\RateLimitPolicy;
use Forwext\Core\Http\Security\RateLimit\RedisRateLimitStore;
use Forwext\Core\Redis\RedisScriptClient;
use PHPUnit\Framework\TestCase;

final class RedisRateLimitStoreTest extends TestCase
{
    public function testConsumesSharedBucketAtomically(): void
    {
        $redis = $this->createMock(RedisScriptClient::class);
        $redis->expects(self::once())
            ->method('evaluate')
            ->with(
                self::stringContains("redis.call('HSET'"),
                self::callback(static fn (array $keys): bool =>
                    count($keys) === 1
                    && is_string($keys[0] ?? null)
                    && preg_match('/^forwext:rate-limit:[a-f0-9]{64}$/D', $keys[0]) === 1
                ),
                ['1000', '60', '10'],
            )
            ->willReturn([1, 10, 9, 1060]);

        $result = (new RedisRateLimitStore($redis))->consume(
            'ip:203.0.113.10',
            new RateLimitPolicy('api.v1.anonymous', 10, 60),
            1000,
        );

        self::assertTrue($result->allowed);
        self::assertSame(10, $result->limit);
        self::assertSame(9, $result->remaining);
        self::assertSame(1060, $result->resetAt);
    }

    public function testRejectsMalformedRedisResult(): void
    {
        $redis = $this->createMock(RedisScriptClient::class);
        $redis->method('evaluate')->willReturn(['broken']);

        $this->expectException(RateLimitException::class);

        (new RedisRateLimitStore($redis))->consume(
            'credential:1',
            new RateLimitPolicy('api.v1.credential', 10, 60),
            1000,
        );
    }
}
