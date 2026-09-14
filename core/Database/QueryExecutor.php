<?php

declare(strict_types=1);

namespace Forwext\Core\Database;

interface QueryExecutor
{
    public function execute(CompiledQuery $query): int;

    /** @return array<string, mixed>|null */
    public function fetchOne(CompiledQuery $query): ?array;

    /** @return list<array<string, mixed>> */
    public function fetchAll(CompiledQuery $query): array;

    public function fetchValue(CompiledQuery $query): mixed;
}
