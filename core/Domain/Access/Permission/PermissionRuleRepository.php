<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Entity\EntityId;

interface PermissionRuleRepository
{
    public function definition(PermissionKey $key): ?PermissionDefinition;

    /** @return list<PermissionRule> */
    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array;
}
