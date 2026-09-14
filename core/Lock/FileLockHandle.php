<?php

declare(strict_types=1);

namespace Forwext\Core\Lock;

use Forwext\Core\Infrastructure\InfrastructureException;

final class FileLockHandle implements LockHandle
{
    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    public function __construct(
        private readonly string $lockName,
        $handle,
    ) {
        if (!is_resource($handle)) {
            throw new InfrastructureException('Invalid file lock handle.');
        }
        $this->handle = $handle;
    }

    public function name(): string
    {
        return $this->lockName;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
