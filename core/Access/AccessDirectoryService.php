<?php

declare(strict_types=1);

namespace Forwext\Core\Access;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class AccessDirectoryService
{
    public function __construct(private AccessDirectoryStore $store)
    {
    }

    public function saveGroup(
        GroupDefinition $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): void {
        $actorId = $this->actor($actorId);
        $existing = $this->store->findGroup($group->key);
        if ($existing !== null && $existing->system !== $group->system) {
            throw new AccessException('Group system classification is immutable.');
        }
        if ($group->system && $actorId !== null) {
            throw new AccessException('System groups cannot be managed through a user action.');
        }
        if (!$group->system && $actorId === null) {
            throw new AccessException('Managed group changes require an actor.');
        }

        $this->store->saveGroup($group, $actorId, $this->reason($reasonCode), self::utc($now));
    }

    public function saveRole(
        RoleDefinition $role,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): void {
        $actorId = $this->actor($actorId);
        $existing = $this->store->findRole($role->key);
        if ($existing !== null && $existing->kind !== $role->kind) {
            throw new AccessException('Role kind is immutable.');
        }
        if ($role->kind === RoleKind::System && $actorId !== null) {
            throw new AccessException('System roles cannot be managed through a user action.');
        }
        if ($role->kind !== RoleKind::System && $actorId === null) {
            throw new AccessException('Managed role changes require an actor.');
        }

        $this->store->saveRole($role, $actorId, $this->reason($reasonCode), self::utc($now));
    }

    public function membershipFor(EntityId $userId): UserAccessMembership
    {
        UserId::assert($userId);
        return $this->store->membershipFor($userId);
    }

    public function setPrimaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        $actorId = $this->actor($actorId);
        $definition = $this->requireActiveGroup($group);
        unset($definition);
        $membership = $this->store->membershipFor($userId);
        if ($membership->primaryGroup->equals($group)) {
            return false;
        }

        return $this->store->setPrimaryGroup(
            $userId,
            $group,
            $actorId,
            $this->reason($reasonCode),
            self::utc($now),
        );
    }

    public function addSecondaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        $actorId = $this->actor($actorId);
        $this->requireActiveGroup($group);
        $membership = $this->store->membershipFor($userId);
        if ($membership->primaryGroup->equals($group)) {
            throw new AccessException('Primary group cannot also be assigned as a secondary group.');
        }
        foreach ($membership->secondaryGroups as $existing) {
            if ($existing->equals($group)) {
                return false;
            }
        }

        return $this->store->addSecondaryGroup(
            $userId,
            $group,
            $actorId,
            $this->reason($reasonCode),
            self::utc($now),
        );
    }

    public function removeSecondaryGroup(
        EntityId $userId,
        GroupKey $group,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        $actorId = $this->actor($actorId);
        return $this->store->removeSecondaryGroup(
            $userId,
            $group,
            $actorId,
            $this->reason($reasonCode),
            self::utc($now),
        );
    }

    public function assignRole(
        EntityId $userId,
        RoleKey $role,
        RoleAssignmentSource $source,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        $actorId = $this->actor($actorId);
        $definition = $this->requireActiveRole($role);
        $this->assertRoleSource($definition, $source, $actorId);

        return $this->store->assignRole(
            $userId,
            $role,
            $source,
            $actorId,
            $this->reason($reasonCode),
            self::utc($now),
        );
    }

    public function revokeRole(
        EntityId $userId,
        RoleKey $role,
        RoleAssignmentSource $source,
        ?EntityId $actorId,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): bool {
        UserId::assert($userId);
        $actorId = $this->actor($actorId);
        $definition = $this->store->findRole($role)
            ?? throw new AccessException('Role is unavailable.');
        $this->assertRoleSource($definition, $source, $actorId);

        return $this->store->revokeRole(
            $userId,
            $role,
            $source,
            $actorId,
            $this->reason($reasonCode),
            self::utc($now),
        );
    }

    private function requireActiveGroup(GroupKey $group): GroupDefinition
    {
        $definition = $this->store->findGroup($group)
            ?? throw new AccessException('Group is unavailable.');
        if (!$definition->active) {
            throw new AccessException('Group is inactive.');
        }
        return $definition;
    }

    private function requireActiveRole(RoleKey $role): RoleDefinition
    {
        $definition = $this->store->findRole($role)
            ?? throw new AccessException('Role is unavailable.');
        if (!$definition->active) {
            throw new AccessException('Role is inactive.');
        }
        return $definition;
    }

    private function assertRoleSource(
        RoleDefinition $role,
        RoleAssignmentSource $source,
        ?EntityId $actorId,
    ): void {
        if ($source === RoleAssignmentSource::Manual && $actorId === null) {
            throw new AccessException('Manual role changes require an actor.');
        }
        if ($role->kind === RoleKind::System && $source !== RoleAssignmentSource::System) {
            throw new AccessException('System roles may only be changed by the system source.');
        }
        if (
            $role->kind === RoleKind::Staff
            && !in_array($source, [RoleAssignmentSource::Manual, RoleAssignmentSource::System], true)
        ) {
            throw new AccessException('Staff roles cannot be granted by promotion or upgrade sources.');
        }
    }

    private function actor(?EntityId $actorId): ?EntityId
    {
        if ($actorId !== null) {
            UserId::assert($actorId);
        }
        return $actorId;
    }

    private function reason(?string $reasonCode): ?string
    {
        if ($reasonCode === null) {
            return null;
        }
        $reasonCode = strtolower(trim($reasonCode));
        if ($reasonCode === '' || preg_match('/^[a-z0-9][a-z0-9._:-]{0,63}$/D', $reasonCode) !== 1) {
            throw new AccessException('Access change reason code is invalid.');
        }
        return $reasonCode;
    }

    private static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }
}
