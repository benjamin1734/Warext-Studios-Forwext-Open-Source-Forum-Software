<?php

declare(strict_types=1);

namespace Forwext\Core\Lock;

use Forwext\Core\Redis\RedisClient;

final class RedisLockHandle implements LockHandle
{
    private bool $released = false;

    public function __construct(
        private readonly string $lockName,
        private readonly string $redisKey,
        private readonly string $token,
        private readonly RedisClient $redis,
    ) {
    }

    public function name(): string
    {
        return $this->lockName;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->redis->deleteIfValueMatches($this->redisKey, $this->token);
        $this->released = true;
    }

    public function __destruct()
    {
        $this->release();
    }
}
