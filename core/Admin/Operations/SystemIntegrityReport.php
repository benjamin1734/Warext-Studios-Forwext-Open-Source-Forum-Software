<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Operations;

final readonly class SystemIntegrityReport
{
    /**
     * @param list<string> $missingMigrations
     * @param list<string> $failedMigrations
     * @param list<string> $runningMigrations
     * @param list<string> $unknownCoreMigrations
     */
    public function __construct(
        public bool $healthy,
        public int $registeredCoreMigrations,
        public int $appliedCoreMigrations,
        public array $missingMigrations,
        public array $failedMigrations,
        public array $runningMigrations,
        public array $unknownCoreMigrations,
        public int $nonInnoDbTables,
    ) {
    }
}
