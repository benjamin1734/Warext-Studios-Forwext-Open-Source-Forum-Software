<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Infrastructure;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Cache\CacheRememberService;
use Forwext\Core\Cache\FileCacheStore;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Lock\FileLockManager;
use Forwext\Core\Session\FileSessionStore;
use PHPUnit\Framework\TestCase;

final class FileDriverTest extends TestCase
{
    private string $directory;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-infra-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC')));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testFileCacheSupportsTtlAndTagInvalidation(): void
    {
        $cache = new FileCacheStore($this->directory . '/cache', $this->clock);
        $cache->put('thread:1', 'value-1', 10, ['threads', 'user:5']);
        $cache->put('thread:2', 'value-2', 10, ['threads']);

        self::assertSame('value-1', $cache->get('thread:1')?->value);
        self::assertSame(2, $cache->invalidateTag('threads'));
        self::assertNull($cache->get('thread:1'));
        self::assertNull($cache->get('thread:2'));

        $cache->put('short', 'expires', 1);
        $this->clock->advanceSeconds(2);
        self::assertNull($cache->get('short'));
    }

    public function testFileSessionExpiresAndGarbageCollectorIsBounded(): void
    {
        $sessions = new FileSessionStore($this->directory . '/sessions', $this->clock);
        $sessions->write('session-a', 'payload-a', 1);
        $sessions->write('session-b', 'payload-b', 100);

        self::assertSame('payload-a', $sessions->read('session-a')?->payload);
        $this->clock->advanceSeconds(2);
        self::assertNull($sessions->read('session-a'));
        self::assertSame('payload-b', $sessions->read('session-b')?->payload);
    }

    public function testFileLockPreventsConcurrentAcquisitionUntilReleased(): void
    {
        $locks = new FileLockManager($this->directory . '/locks');
        $first = $locks->acquire('rebuild:index');
        self::assertNotNull($first);
        self::assertNull($locks->acquire('rebuild:index'));

        $first->release();
        $second = $locks->acquire('rebuild:index');
        self::assertNotNull($second);
        $second->release();
    }

    public function testRememberUsesCacheAndDoesNotRecomputeExistingValue(): void
    {
        $cache = new FileCacheStore($this->directory . '/cache', $this->clock);
        $remember = new CacheRememberService($cache, new FileLockManager($this->directory . '/locks'));
        $calls = 0;

        $first = $remember->remember('expensive', 60, static function () use (&$calls): string {
            ++$calls;
            return 'computed';
        });
        $second = $remember->remember('expensive', 60, static function () use (&$calls): string {
            ++$calls;
            return 'unexpected';
        });

        self::assertSame('computed', $first);
        self::assertSame('computed', $second);
        self::assertSame(1, $calls);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $current)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->current = $this->current->add(new DateInterval('PT' . $seconds . 'S'));
    }
}
