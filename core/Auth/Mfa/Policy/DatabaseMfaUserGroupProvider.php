<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Policy;

use Forwext\Core\Domain\Access\DatabaseUserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class DatabaseMfaUserGroupProvider implements UserGroupProvider
{
    public function __construct(private DatabaseUserAccessAssignmentProvider $assignments)
    {
    }

    public function groupsFor(EntityId $userId): array
    {
        $assignment = $this->assignments->find($userId);
        if ($assignment === null) {
            return [];
        }

        $groups = [$assignment->primaryGroupId()->value()];
        foreach ($assignment->secondaryGroupIds() as $groupId) {
            $groups[] = $groupId->value();
        }

        return array_values(array_unique($groups));
    }
}
