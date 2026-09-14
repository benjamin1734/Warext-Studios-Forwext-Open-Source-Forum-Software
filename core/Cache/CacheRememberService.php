<?php

declare(strict_types=1);

namespace Forwext\Core\Cache;

use Closure;
use Forwext\Core\Infrastructure\InfrastructureException;
use Forwext\Core\Lock\LockManager;

final readonly class CacheRememberService
{
    public function __construct(
        private CacheStore $cache,
        private LockManager $locks,
    ) {
    }

    /** @param Closure(): string $producer */
    public function remember(
        string $key,
        int $ttlSeconds,
        Closure $producer,
        array $tags = [],
        int $lockWaitMilliseconds = 1000,
    ): string {
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            return $cached->value;
        }

        $lock = $this->locks->acquire('cache:' . hash('sha256', $key), 30, $lockWaitMilliseconds);
        if ($lock === null) {
            throw new InfrastructureException('Unable to acquire cache regeneration lock.');
        }

        try {
            $cached = $this->cache->get($key);
            if ($cached !== null) {
                return $cached->value;
            }

            $value = $producer();
            $this->cache->put($key, $value, $ttlSeconds, $tags);
            return $value;
        } finally {
            $lock->release();
        }
    }
}
