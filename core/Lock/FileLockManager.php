<?php

declare(strict_types=1);

namespace Forwext\Core\Lock;

use Forwext\Core\Infrastructure\InfrastructureException;
use Forwext\Core\Infrastructure\KeyValidator;

final readonly class FileLockManager implements LockManager
{
    public function __construct(private string $directory)
    {
    }

    public function acquire(string $name, int $ttlSeconds = 30, int $waitMilliseconds = 0): ?LockHandle
    {
        $name = KeyValidator::lock($name);
        if ($ttlSeconds < 1 || $waitMilliseconds < 0) {
            throw new InfrastructureException('Lock TTL must be positive and wait time cannot be negative.');
        }
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new InfrastructureException('Unable to create lock directory.');
        }

        $path = $this->directory . '/' . hash('sha256', $name) . '.lock';
        if (is_link($path)) {
            throw new InfrastructureException('Lock file may not be a symbolic link.');
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new InfrastructureException('Unable to open lock file.');
        }
        @chmod($path, 0600);

        $deadline = microtime(true) + ($waitMilliseconds / 1000);
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new FileLockHandle($name, $handle);
            }
            if ($waitMilliseconds === 0) {
                fclose($handle);
                return null;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        fclose($handle);
        return null;
    }
}
