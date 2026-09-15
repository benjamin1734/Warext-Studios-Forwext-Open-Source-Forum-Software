<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class UserProfile
{
    /**
     * @param list<SocialLink> $socialLinks
     * @param list<ProfileTab> $tabs
     */
    public function __construct(
        public EntityId $userId,
        public string $about,
        public ?string $avatarPath,
        public ?string $bannerPath,
        public ProfileVisibility $profileVisibility,
        public ProfileVisibility $aboutVisibility,
        public ProfileVisibility $socialVisibility,
        public ProfileVisibility $mediaVisibility,
        public array $socialLinks,
        public array $tabs,
        public DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($userId);
        self::assertUtf8Length($about, 5000, 'Profile about text');
        self::assertStoragePath($avatarPath, 'avatar');
        self::assertStoragePath($bannerPath, 'banner');

        if (count($socialLinks) > 10) {
            throw new ProfileException('Profile social link limit exceeded.');
        }

        if (count($tabs) > 20) {
            throw new ProfileException('Profile tab limit exceeded.');
        }

        $linkKeys = [];
        foreach ($socialLinks as $link) {
            if (!$link instanceof SocialLink || isset($linkKeys[$link->key])) {
                throw new ProfileException('Profile social links must be typed and unique.');
            }
            $linkKeys[$link->key] = true;
        }

        $tabKeys = [];
        foreach ($tabs as $tab) {
            if (!$tab instanceof ProfileTab || isset($tabKeys[$tab->key])) {
                throw new ProfileException('Profile tabs must be typed and unique.');
            }
            $tabKeys[$tab->key] = true;
        }

        if ($updatedAt->getTimezone()->getName() !== 'UTC') {
            throw new ProfileException('Profile timestamps must be normalized to UTC.');
        }
    }

    private static function assertUtf8Length(string $value, int $maxCharacters, string $label): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new ProfileException($label . ' must contain valid UTF-8.');
        }

        $count = preg_match_all('/./us', $value);
        if ($count === false || $count > $maxCharacters) {
            throw new ProfileException($label . ' exceeds the allowed length.');
        }
    }

    private static function assertStoragePath(?string $path, string $kind): void
    {
        if ($path === null) {
            return;
        }

        if (
            strlen($path) > 1024
            || preg_match('/^profiles\/[a-f0-9]{32}\/' . $kind . '\/[a-f0-9]{64}\.(?:jpg|png|webp)$/D', $path) !== 1
        ) {
            throw new ProfileException('Stored profile media path is invalid.');
        }
    }

    public static function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
