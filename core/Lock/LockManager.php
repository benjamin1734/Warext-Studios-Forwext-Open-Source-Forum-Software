<?php

declare(strict_types=1);

namespace Forwext\Core\Lock;

interface LockManager
{
    public function acquire(string $name, int $ttlSeconds = 30, int $waitMilliseconds = 0): ?LockHandle;
}
