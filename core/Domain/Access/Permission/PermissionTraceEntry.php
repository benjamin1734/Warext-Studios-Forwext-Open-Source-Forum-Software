<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

final readonly class PermissionTraceEntry
{
    public function __construct(
        private string $tier,
        private PermissionRule $rule,
        private string $outcome,
    ) {
    }

    public function tier(): string
    {
        return $this->tier;
    }

    public function rule(): PermissionRule
    {
        return $this->rule;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }
}
