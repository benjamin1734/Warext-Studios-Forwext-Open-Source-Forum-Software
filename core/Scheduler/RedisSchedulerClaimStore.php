<?php

declare(strict_types=1);

namespace Forwext\Core\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Redis\RedisClient;

final readonly class RedisSchedulerClaimStore implements SchedulerClaimStore
{
    public function __construct(
        private RedisClient $redis,
        private string $prefix = 'forwext:scheduler:',
        private int $ttlSeconds = 172800,
    ) {
        if ($prefix === '' || $ttlSeconds < 60 || $ttlSeconds > 604800) {
            throw new \InvalidArgumentException('Redis scheduler claim configuration is invalid.');
        }
    }

    public function claim(string $taskName, DateTimeImmutable $minuteBucket): ?SchedulerClaim
    {
        $claim = new SchedulerClaim(
            $taskName,
            self::minute($minuteBucket),
            bin2hex(random_bytes(16)),
        );

        if (!$this->redis->setIfAbsent($this->key($claim), $claim->token, $this->ttlSeconds * 1000)) {
            return null;
        }

        return $claim;
    }

    public function release(SchedulerClaim $claim): void
    {
        $this->redis->deleteIfValueMatches($this->key($claim), $claim->token);
    }

    public function pruneBefore(DateTimeImmutable $cutoff, int $limit = 1000): int
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Scheduler prune limit must be positive.');
        }

        return 0;
    }

    private function key(SchedulerClaim $claim): string
    {
        return $this->prefix
            . hash('sha256', $claim->taskName)
            . ':'
            . $claim->minuteBucket->setTimezone(new DateTimeZone('UTC'))->format('YmdHi');
    }

    private static function minute(DateTimeImmutable $time): DateTimeImmutable
    {
        $utc = $time->setTimezone(new DateTimeZone('UTC'));
        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), 0, 0);
    }
}
