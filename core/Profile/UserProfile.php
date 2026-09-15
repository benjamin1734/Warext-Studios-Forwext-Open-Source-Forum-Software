<?php

declare(strict_types=1);
namespace Forwext\Core\Profile;
use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
final readonly class UserProfile
{
    /** @param list<SocialLink> $socialLinks @param list<ProfileTab> $tabs */
    public function __construct(
        public EntityId $userId, public string $about, public ?string $avatarPath, public ?string $bannerPath,
        public ProfileVisibility $profileVisibility, public ProfileVisibility $aboutVisibility,
        public ProfileVisibility $socialVisibility, public ProfileVisibility $mediaVisibility,
        public array $socialLinks, public array $tabs, public DateTimeImmutable $updatedAt,
    ) {}
}
