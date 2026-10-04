<?php

declare(strict_types=1);

namespace Forwext\Core\CommunityGroup;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class CommunityGroupMember
{
    public function __construct(
        public EntityId $groupId,
        public EntityId $userId,
        public string $roleKey,
        public string $state,
        public ?EntityId $actedByUserId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($userId);
        if ($actedByUserId !== null) {
            UserId::assert($actedByUserId);
        }
        if (!in_array($roleKey, ['owner','moderator','member'], true)) {
            throw new InvalidArgumentException('Community group member role is invalid.');
        }
        if (!in_array($state, ['active','pending'], true)) {
            throw new InvalidArgumentException('Community group membership state is invalid.');
        }
        if ($roleKey === 'owner' && $state !== 'active') {
            throw new InvalidArgumentException('Community group owner must be active.');
        }
    }

    public function active(): bool
    {
        return $this->state === 'active';
    }

    public function managesMembers(): bool
    {
        return $this->active() && in_array($this->roleKey, ['owner','moderator'], true);
    }
}
