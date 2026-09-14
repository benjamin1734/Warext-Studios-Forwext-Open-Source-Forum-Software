<?php

declare(strict_types=1);

namespace Forwext\Core\Lock;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;

final class DatabaseLockHandle implements LockHandle
{
    private bool $released = false;

    public function __construct(
        private readonly string $lockName,
        private readonly string $lockHash,
        private readonly string $token,
        private readonly TransactionalQueryExecutor $database,
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
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_locks` WHERE `lock_hash` = :lock_hash AND `token` = :token',
            [
                'lock_hash' => $this->lockHash,
                'token' => $this->token,
            ],
        ));
        $this->released = true;
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (\Throwable) {
            // Destructors must not turn lock cleanup into a fatal shutdown path.
        }
    }
}
