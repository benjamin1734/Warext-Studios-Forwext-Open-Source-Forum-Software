<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

final readonly class MigrationRunReport
{
    /**
     * @param list<string> $applied
     * @param list<string> $skipped
     */
    public function __construct(
        public int $batch,
        public array $applied,
        public array $skipped,
    ) {
    }
}
