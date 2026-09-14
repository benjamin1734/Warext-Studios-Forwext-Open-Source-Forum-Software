<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;

final readonly class MigrationContext
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function database(): TransactionalQueryExecutor
    {
        return $this->database;
    }

    public function execute(CompiledQuery $query): int
    {
        return $this->database->execute($query);
    }

    /** @return array<string, mixed>|null */
    public function fetchOne(CompiledQuery $query): ?array
    {
        return $this->database->fetchOne($query);
    }

    /** @return list<array<string, mixed>> */
    public function fetchAll(CompiledQuery $query): array
    {
        return $this->database->fetchAll($query);
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return $this->database->fetchValue($query);
    }
}
