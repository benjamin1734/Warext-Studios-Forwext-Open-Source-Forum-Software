<?php

declare(strict_types=1);
namespace Forwext\Core\Profile;
use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
final readonly class ProfileService
{
    public function __construct(private ProfileStore $store, private int $aboutMaxCharacters = 5000, private int $socialLinkLimit = 10) {}

    public function getOrDefault(EntityId $userId, DateTimeImmutable $now): UserProfile
    {
        UserId::assert($userId);
        return $this->store->find($userId) ?? new UserProfile(
            $userId, '', null, null, ProfileVisibility::Public, ProfileVisibility::Public,
            ProfileVisibility::Public, ProfileVisibility::Public, [], self::defaultTabs(), $now,
        );
    }

    /** @param list<SocialLink> $links @param list<ProfileTab> $tabs */
    public function update(
        EntityId $userId, string $about, ProfileVisibility $profileVisibility,
        ProfileVisibility $aboutVisibility, ProfileVisibility $socialVisibility,
        ProfileVisibility $mediaVisibility, array $links, array $tabs, DateTimeImmutable $now,
    ): UserProfile {
        UserId::assert($userId);
        if (preg_match('//u', $about) !== 1 || mb_strlen($about, 'UTF-8') > $this->aboutMaxCharacters) { throw new ProfileException('Profile about text is invalid or too long.'); }
        if (count($links) > $this->socialLinkLimit) { throw new ProfileException('Profile social link limit exceeded.'); }
        $linkKeys = []; foreach ($links as $link) { if (!$link instanceof SocialLink || isset($linkKeys[$link->key])) { throw new ProfileException('Profile social links must be typed and unique.'); } $linkKeys[$link->key] = true; }
        $tabKeys = []; foreach ($tabs as $tab) { if (!$tab instanceof ProfileTab || isset($tabKeys[$tab->key])) { throw new ProfileException('Profile tabs must be typed and unique.'); } $tabKeys[$tab->key] = true; }
        $current = $this->getOrDefault($userId, $now);
        $profile = new UserProfile($userId, trim($about), $current->avatarPath, $current->bannerPath, $profileVisibility, $aboutVisibility, $socialVisibility, $mediaVisibility, $links, $tabs, $now);
        $this->store->save($profile);
        return $profile;
    }

    /** @return list<ProfileTab> */
    public static function defaultTabs(): array
    {
        return [
            new ProfileTab('overview', true, ProfileVisibility::Public, 0),
            new ProfileTab('activity', true, ProfileVisibility::Public, 10),
            new ProfileTab('about', true, ProfileVisibility::Public, 20),
        ];
    }
}
