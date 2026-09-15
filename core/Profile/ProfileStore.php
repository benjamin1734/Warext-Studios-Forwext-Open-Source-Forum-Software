<?php

declare(strict_types=1);
namespace Forwext\Core\Profile;
use Forwext\Core\Domain\Entity\EntityId;
interface ProfileStore
{
    public function find(EntityId $userId): ?UserProfile;
    public function save(UserProfile $profile): void;
    public function updateMedia(EntityId $userId, ProfileMediaKind $kind, ?string $path): void;
}
