<?php

declare(strict_types=1);

namespace Forwext\Core\CommunityGroup;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface CommunityGroupRepository
{
    /** @return list<CommunityGroup> */
    public function directory(?string $query = null, int $limit = 30, int $offset = 0): array;

    /** @return list<CommunityGroup> */
    public function mine(EntityId $userId, int $limit = 100): array;

    public function byId(EntityId $groupId): ?CommunityGroup;

    public function bySlug(string $slug): ?CommunityGroup;

    public function create(CommunityGroup $group, CommunityGroupMember $owner): void;

    /** @return list<CommunityGroupMember> */
    public function members(EntityId $groupId, bool $includePending = false, int $limit = 200): array;

    public function membership(EntityId $groupId, EntityId $userId): ?CommunityGroupMember;

    public function requestMembership(
        EntityId $groupId,
        EntityId $userId,
        string $targetState,
        DateTimeImmutable $now,
    ): void;

    public function setMembership(
        EntityId $groupId,
        EntityId $userId,
        string $roleKey,
        string $state,
        EntityId $actorUserId,
        DateTimeImmutable $now,
    ): void;

    public function removeMembership(
        EntityId $groupId,
        EntityId $userId,
        EntityId $actorUserId,
        DateTimeImmutable $now,
    ): bool;
}
