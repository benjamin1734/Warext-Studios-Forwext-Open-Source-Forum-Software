<?php

declare(strict_types=1);

namespace Forwext\Core\Lock;

use Forwext\Core\Infrastructure\InfrastructureException;
use Forwext\Core\Infrastructure\KeyValidator;
use Forwext\Core\Redis\RedisClient;

final readonly class RedisLockManager implements LockManager
{
    public function __construct(
        private RedisClient $redis,
        private string $prefix = 'forwext:lock:',
    ) {
    }

    public function acquire(string $name, int $ttlSeconds = 30, int $waitMilliseconds = 0): ?LockHandle
    {
        $name = KeyValidator::lock($name);
        if ($ttlSeconds < 1 || $waitMilliseconds < 0) {
            throw new InfrastructureException('Lock TTL must be positive and wait time cannot be negative.');
        }
        $token = bin2hex(random_bytes(16));
        $deadline = microtime(true) + ($waitMilliseconds / 1000);
        do {
            if ($this->redis->setIfAbsent($this->key($name), $token, $ttlSeconds * 1000)) {
                return new RedisLockHandle($name, $this->key($name), $token, $this->redis);
            }
            if ($waitMilliseconds === 0) {
                return null;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        return null;
    }

    private function key(string $name): string
    {
        return $this->prefix . hash('sha256', $name);
    }
}
