<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Diagnostic;

use Forwext\Core\Domain\Entity\EntityId;

interface BugDiagnosticContextRepository
{
    public function save(BugDiagnosticContext $context): void;
    public function find(EntityId $reportId): ?BugDiagnosticContext;
}
