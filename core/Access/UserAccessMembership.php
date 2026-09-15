<?php

declare(strict_types=1);

namespace Forwext\Core\Access;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class UserAccessMembership
{
    /** @var list<GroupKey> */
    public array $secondaryGroups;

    /** @var list<RoleAssignment> */
    public array $roles;

    /**
     * @param iterable<GroupKey> $secondaryGroups
     * @param iterable<RoleAssignment> $roles
     */
    public function __construct(
        public EntityId $userId,
        public GroupKey $primaryGroup,
        iterable $secondaryGroups = [],
        iterable $roles = [],
    ) {
        UserId::assert($userId);

        $groups = [];
        foreach ($secondaryGroups as $group) {
            if (!$group instanceof GroupKey) {
                throw new AccessException('Secondary group collection contains an invalid entry.');
            }
            if ($group->equals($primaryGroup)) {
                throw new AccessException('Primary group cannot also be a secondary group.');
            }
            $groups[$group->value()] = $group;
        }
        ksort($groups, SORT_STRING);
        $this->secondaryGroups = array_values($groups);

        $assignments = [];
        foreach ($roles as $assignment) {
            if (!$assignment instanceof RoleAssignment) {
                throw new AccessException('Role assignment collection contains an invalid entry.');
            }
            $key = $assignment->role->value();
            if (isset($assignments[$key])) {
                throw new AccessException('Role assignment collection contains a duplicate role.');
            }
            $assignments[$key] = $assignment;
        }
        ksort($assignments, SORT_STRING);
        $this->roles = array_values($assignments);
    }

    /** @return list<GroupKey> */
    public function allGroups(): array
    {
        return [$this->primaryGroup, ...$this->secondaryGroups];
    }

    public function hasGroup(GroupKey $key): bool
    {
        foreach ($this->allGroups() as $group) {
            if ($group->equals($key)) {
                return true;
            }
        }
        return false;
    }

    public function hasRole(RoleKey $key): bool
    {
        foreach ($this->roles as $assignment) {
            if ($assignment->role->equals($key)) {
                return true;
            }
        }
        return false;
    }
}
