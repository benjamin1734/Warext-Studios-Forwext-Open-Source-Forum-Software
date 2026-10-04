<?php

declare(strict_types=1);

namespace Forwext\Core\CommunityGroup;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class CommunityGroupService
{
    public function __construct(
        private CommunityGroupRepository $groups,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    /** @return list<CommunityGroup> */
    public function directory(?EntityId $actor, ?string $query = null, int $limit = 30, int $offset = 0): array
    {
        if ($actor !== null) {
            $this->require($actor, 'group.view');
        }
        return $this->groups->directory($query, $limit, $offset);
    }

    /** @return list<CommunityGroup> */
    public function mine(EntityId $actor, int $limit = 100): array
    {
        $this->require($actor, 'group.view');
        return $this->groups->mine($actor, $limit);
    }

    public function detail(EntityId $groupId, ?EntityId $actor): CommunityGroup
    {
        $group = $this->groups->byId($groupId)
            ?? throw new InvalidArgumentException('Community group was not found.');
        if (!$group->isActive() && ($actor === null || !$this->canManage($actor, $group))) {
            throw new InvalidArgumentException('Community group is unavailable.');
        }
        if ($actor !== null) {
            $this->require($actor, 'group.view');
        }
        return $group;
    }

    /** @return list<CommunityGroupMember> */
    public function members(EntityId $groupId, ?EntityId $actor): array
    {
        $group = $this->detail($groupId, $actor);
        $includePending = $actor !== null && $this->canManage($actor, $group);
        return $this->groups->members($groupId, $includePending);
    }

    public function membership(EntityId $groupId, EntityId $actor): ?CommunityGroupMember
    {
        return $this->groups->membership($groupId, $actor);
    }

    public function canCreate(EntityId $actor): bool
    {
        return $this->allows($actor, 'group.create');
    }

    public function create(
        EntityId $actor,
        string $slug,
        string $name,
        string $tagline,
        string $description,
        string $joinPolicy,
        DateTimeImmutable $now,
    ): EntityId {
        $this->require($actor, 'group.create');
        $slug = strtolower(trim($slug));
        $name = trim($name);
        $tagline = trim($tagline);
        $description = trim($description);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 120) {
            throw new InvalidArgumentException('Community group slug is invalid.');
        }
        if ($this->groups->bySlug($slug) !== null) {
            throw new InvalidArgumentException('Community group slug is already in use.');
        }
        $groupId = EntityId::fromString(bin2hex(random_bytes(16)));
        $group = new CommunityGroup(
            $groupId,
            $actor,
            $slug,
            $name,
            $tagline,
            $description,
            $joinPolicy,
            'active',
            1,
            0,
            $now,
            $now,
        );
        $owner = new CommunityGroupMember(
            $groupId,
            $actor,
            'owner',
            'active',
            $actor,
            $now,
            $now,
        );
        $this->groups->create($group, $owner);
        return $groupId;
    }

    public function join(EntityId $actor, EntityId $groupId, DateTimeImmutable $now): string
    {
        $this->require($actor, 'group.join');
        $group = $this->detail($groupId, $actor);
        $current = $this->groups->membership($groupId, $actor);
        if ($current !== null && $current->active()) {
            throw new InvalidArgumentException('Community group membership is already active.');
        }
        $state = match ($group->joinPolicy) {
            'open' => 'active',
            'approval' => 'pending',
            default => throw new InvalidArgumentException('Community group does not accept membership requests.'),
        };
        $this->groups->requestMembership($groupId, $actor, $state, $now);
        return $state;
    }

    public function leave(EntityId $actor, EntityId $groupId, DateTimeImmutable $now): void
    {
        $this->require($actor, 'group.join');
        $group = $this->detail($groupId, $actor);
        if ($group->ownerUserId->equals($actor)) {
            throw new InvalidArgumentException('Community group owner cannot leave the group.');
        }
        if (!$this->groups->removeMembership($groupId, $actor, $actor, $now)) {
            throw new InvalidArgumentException('Community group membership was not found.');
        }
    }

    public function canManage(EntityId $actor, CommunityGroup $group): bool
    {
        if ($this->allows($actor, 'group.moderate_any')) {
            return true;
        }
        if (!$this->allows($actor, 'group.manage_own')) {
            return false;
        }
        $membership = $this->groups->membership($group->groupId, $actor);
        return $membership?->managesMembers() ?? false;
    }

    public function manageMembership(
        EntityId $actor,
        EntityId $groupId,
        EntityId $targetUserId,
        string $action,
        DateTimeImmutable $now,
    ): void {
        $group = $this->detail($groupId, $actor);
        if (!$this->canManage($actor, $group)) {
            $this->require($actor, 'group.moderate_any');
        }
        $target = $this->groups->membership($groupId, $targetUserId)
            ?? throw new InvalidArgumentException('Community group membership was not found.');
        if ($target->roleKey === 'owner' || $group->ownerUserId->equals($targetUserId)) {
            throw new InvalidArgumentException('Community group owner membership cannot be changed.');
        }

        $actorMembership = $this->groups->membership($groupId, $actor);
        $globalModerator = $this->allows($actor, 'group.moderate_any');
        $roleAuthority = $globalModerator || ($actorMembership?->roleKey === 'owner' && $actorMembership->active());

        if ($target->roleKey === 'moderator' && !$roleAuthority) {
            throw new PermissionDeniedException(
                $this->authorizer->resolve($actor, PermissionKey::fromString('group.moderate_any')),
            );
        }

        switch ($action) {
            case 'approve':
                $this->groups->setMembership($groupId, $targetUserId, 'member', 'active', $actor, $now);
                return;
            case 'promote':
                if (!$roleAuthority) {
                    $this->require($actor, 'group.moderate_any');
                }
                $this->groups->setMembership($groupId, $targetUserId, 'moderator', 'active', $actor, $now);
                return;
            case 'demote':
                if (!$roleAuthority) {
                    $this->require($actor, 'group.moderate_any');
                }
                $this->groups->setMembership($groupId, $targetUserId, 'member', 'active', $actor, $now);
                return;
            case 'remove':
                if (!$this->groups->removeMembership($groupId, $targetUserId, $actor, $now)) {
                    throw new InvalidArgumentException('Community group membership was not found.');
                }
                return;
            default:
                throw new InvalidArgumentException('Community group membership action is invalid.');
        }
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function require(EntityId $actor, string $permission): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    private function allows(EntityId $actor, string $permission): bool
    {
        return $this->authorizer->allows($actor, PermissionKey::fromString($permission));
    }
}
