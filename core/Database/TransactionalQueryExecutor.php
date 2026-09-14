<?php

declare(strict_types=1);

namespace Forwext\Core\Database;

use Closure;

interface TransactionalQueryExecutor extends QueryExecutor
{
    public function inTransaction(): bool;

    /**
     * @template T
     * @param Closure(self): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed;
}
