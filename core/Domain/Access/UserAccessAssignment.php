<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final class UserAccessAssignment
{
    /** @var array<string, EntityId> */
    private array $secondaryGroups = [];

    /** @var array<string, EntityId> */
    private array $roles = [];

    /**
     * @param list<EntityId> $secondaryGroups
     * @param list<EntityId> $roles
     */
    public function __construct(
        private readonly EntityId $userId,
        private EntityId $primaryGroupId,
        array $secondaryGroups = [],
        array $roles = [],
    ) {
        foreach ($secondaryGroups as $groupId) {
            $this->addSecondaryGroup($groupId);
        }
        foreach ($roles as $roleId) {
            $this->assignRole($roleId);
        }
    }

    public function userId(): EntityId
    {
        return $this->userId;
    }

    public function primaryGroupId(): EntityId
    {
        return $this->primaryGroupId;
    }

    /** @return list<EntityId> */
    public function secondaryGroupIds(): array
    {
        return array_values($this->secondaryGroups);
    }

    /** @return list<EntityId> */
    public function roleIds(): array
    {
        return array_values($this->roles);
    }

    public function changePrimaryGroup(EntityId $groupId): void
    {
        unset($this->secondaryGroups[$groupId->value()]);
        $this->primaryGroupId = $groupId;
    }

    public function addSecondaryGroup(EntityId $groupId): void
    {
        if ($groupId->equals($this->primaryGroupId)) {
            throw new InvalidArgumentException('Primary group cannot also be a secondary group.');
        }
        $this->secondaryGroups[$groupId->value()] = $groupId;
    }

    public function removeSecondaryGroup(EntityId $groupId): void
    {
        unset($this->secondaryGroups[$groupId->value()]);
    }

    public function assignRole(EntityId $roleId): void
    {
        $this->roles[$roleId->value()] = $roleId;
    }

    public function revokeRole(EntityId $roleId): void
    {
        unset($this->roles[$roleId->value()]);
    }
}
