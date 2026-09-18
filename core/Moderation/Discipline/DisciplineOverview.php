<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

final readonly class DisciplineOverview
{
    /**
     * @param list<WarningDefinition> $warningDefinitions
     * @param list<DisciplineAction> $actions
     */
    public function __construct(
        public array $warningDefinitions,
        public array $actions,
    ) {
    }
}
