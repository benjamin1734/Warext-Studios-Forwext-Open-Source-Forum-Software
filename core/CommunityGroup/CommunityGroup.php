<?php

declare(strict_types=1);

namespace Forwext\Core\CommunityGroup;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class CommunityGroup
{
    public function __construct(
        public EntityId $groupId,
        public EntityId $ownerUserId,
        public string $slug,
        public string $name,
        public string $tagline,
        public string $description,
        public string $joinPolicy,
        public string $state,
        public int $activeMemberCount,
        public int $pendingMemberCount,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($ownerUserId);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 || strlen($slug) > 120) {
            throw new InvalidArgumentException('Community group slug is invalid.');
        }
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Community group name is invalid.');
        }
        if (mb_strlen($tagline) > 240 || mb_strlen($description) > 20000) {
            throw new InvalidArgumentException('Community group text is too long.');
        }
        if (!in_array($joinPolicy, ['open','approval','closed'], true)) {
            throw new InvalidArgumentException('Community group join policy is invalid.');
        }
        if (!in_array($state, ['active','archived'], true)) {
            throw new InvalidArgumentException('Community group state is invalid.');
        }
        if ($activeMemberCount < 0 || $pendingMemberCount < 0) {
            throw new InvalidArgumentException('Community group member count cannot be negative.');
        }
    }

    public function isActive(): bool
    {
        return $this->state === 'active';
    }
}
