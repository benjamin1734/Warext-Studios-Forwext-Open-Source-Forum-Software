<?php

declare(strict_types=1);

namespace Forwext\Core\Access;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface AccessDirectoryStore
{
    public function findGroup(GroupKey $key): ?GroupDefinition;

    public function findRole(RoleKey $key): ?RoleDefinition;

    public function saveGroup(
        GroupDefinition $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): void;

    public function saveRole(
        RoleDefinition $role,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): void;

    public function membershipFor(EntityId $userId): UserAccessMembership;

    public function setPrimaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool;

    public function addSecondaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool;

    public function removeSecondaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool;

    public function assignRole(
        EntityId $userId,
        RoleKey $role,
        RoleAssignmentSource $source,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool;

    public function revokeRole(
        EntityId $userId,
        RoleKey $role,
        RoleAssignmentSource $source,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool;
}
