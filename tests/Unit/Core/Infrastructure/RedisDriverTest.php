<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Infrastructure;

use Forwext\Core\Cache\RedisCacheStore;
use Forwext\Core\Lock\RedisLockManager;
use Forwext\Core\Redis\RedisClient;
use Forwext\Core\Session\RedisSessionStore;
use PHPUnit\Framework\TestCase;

final class RedisDriverTest extends TestCase
{
    public function testRedisCacheTagInvalidationAndOverwriteCleanup(): void
    {
        $redis = new FakeRedisClient();
        $cache = new RedisCacheStore($redis);
        $cache->put('item:1', 'v1', 60, ['group:a']);
        $cache->put('item:1', 'v2', 60, ['group:b']);

        self::assertSame(0, $cache->invalidateTag('group:a'));
        self::assertSame('v2', $cache->get('item:1')?->value);
        self::assertSame(1, $cache->invalidateTag('group:b'));
        self::assertNull($cache->get('item:1'));
    }

    public function testRedisSessionAndTokenSafeLockSemantics(): void
    {
        $redis = new FakeRedisClient();
        $sessions = new RedisSessionStore($redis);
        $sessions->write('session-1', 'payload', 60);
        self::assertSame('payload', $sessions->read('session-1')?->payload);

        $locks = new RedisLockManager($redis);
        $first = $locks->acquire('job:1');
        self::assertNotNull($first);
        self::assertNull($locks->acquire('job:1'));
        $first->release();
        self::assertNotNull($locks->acquire('job:1'));
    }
}

final class FakeRedisClient implements RedisClient
{
    /** @var array<string, string> */
    private array $values = [];
    /** @var array<string, array<string, true>> */
    private array $sets = [];

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): void
    {
        $this->values[$key] = $value;
    }

    public function setIfAbsent(string $key, string $value, int $ttlMilliseconds): bool
    {
        if (isset($this->values[$key])) {
            return false;
        }
        $this->values[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        $existed = isset($this->values[$key]) || isset($this->sets[$key]);
        unset($this->values[$key], $this->sets[$key]);
        return $existed;
    }

    public function addSetMember(string $key, string $member, ?int $ttlSeconds = null): void
    {
        $this->sets[$key][$member] = true;
    }

    public function removeSetMember(string $key, string $member): bool
    {
        $existed = isset($this->sets[$key][$member]);
        unset($this->sets[$key][$member]);
        return $existed;
    }

    public function setMembers(string $key): array
    {
        return array_keys($this->sets[$key] ?? []);
    }

    public function deleteIfValueMatches(string $key, string $expectedValue): bool
    {
        if (($this->values[$key] ?? null) !== $expectedValue) {
            return false;
        }
        unset($this->values[$key]);
        return true;
    }
}
