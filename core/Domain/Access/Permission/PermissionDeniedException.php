<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

use RuntimeException;

final class PermissionDeniedException extends RuntimeException
{
    public function __construct(private readonly PermissionDecision $decision)
    {
        parent::__construct('Permission denied.');
    }

    public function decision(): PermissionDecision
    {
        return $this->decision;
    }
}
