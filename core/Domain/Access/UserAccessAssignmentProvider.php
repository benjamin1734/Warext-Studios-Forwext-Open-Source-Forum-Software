<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access;

use Forwext\Core\Domain\Entity\EntityId;

interface UserAccessAssignmentProvider
{
    public function find(EntityId $userId): ?UserAccessAssignment;
}
