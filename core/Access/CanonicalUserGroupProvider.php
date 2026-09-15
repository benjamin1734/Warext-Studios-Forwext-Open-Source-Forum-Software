<?php

declare(strict_types=1);

namespace Forwext\Core\Access;

use Forwext\Core\Auth\Mfa\Policy\UserGroupProvider;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class CanonicalUserGroupProvider implements UserGroupProvider
{
    public function __construct(private AccessDirectoryStore $store)
    {
    }

    public function groupsFor(EntityId $userId): array
    {
        return array_map(
            static fn (GroupKey $group): string => $group->value(),
            $this->store->membershipFor($userId)->allGroups(),
        );
    }
}
