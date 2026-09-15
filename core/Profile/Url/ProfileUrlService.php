<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Url;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Profile\ProfileAccessPolicy;

final readonly class ProfileUrlService
{
    public function __construct(
        private ProfileUrlStore $store,
        private ProfileAccessPolicy $profileAccess,
        private ProfileUrlPermissionResolver $permissions,
        private ProfileSlugPolicy $slugPolicy,
        private int $minimumChangeIntervalSeconds = 86400,
        private int $changeWindowSeconds = 2592000,
        private int $maximumChangesPerWindow = 3,
    ) {
        if (
            $minimumChangeIntervalSeconds < 0
            || $changeWindowSeconds < 1
            || $maximumChangesPerWindow < 0
            || $minimumChangeIntervalSeconds > $changeWindowSeconds
        ) {
            throw new ProfileUrlException('Custom profile URL rate-limit policy is invalid.');
        }
    }

    public function current(EntityId $userId): ?ProfileUrlAssignment
    {
        UserId::assert($userId);
        return $this->store->findCurrent($userId);
    }

    public function assign(
        EntityId $userId,
        EntityId $actorId,
        string $requestedSlug,
        DateTimeImmutable $now,
    ): ProfileUrlAssignment {
        UserId::assert($userId);
        UserId::assert($actorId);
        if (
            !$this->profileAccess->canEdit($userId, $actorId)
            || !$this->permissions->allows($userId, ProfileUrlPermission::Use)
        ) {
            throw new ProfileUrlException('Custom profile URL change is not permitted.');
        }

        $slug = ProfileSlug::fromString($requestedSlug);
        $this->slugPolicy->assertAllowed($slug);

        return $this->store->claim(
            $userId,
            $slug,
            ProfileUrlAssignment::utc($now),
            $this->minimumChangeIntervalSeconds,
            $this->changeWindowSeconds,
            $this->maximumChangesPerWindow,
        );
    }

    public function resolve(string $requestedSlug): ?ProfileUrlResolution
    {
        try {
            $slug = ProfileSlug::fromString($requestedSlug);
        } catch (ProfileUrlException) {
            return null;
        }

        return $this->store->resolve($slug);
    }
}
