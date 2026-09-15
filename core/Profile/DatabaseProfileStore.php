<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseProfileStore implements ProfileStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function find(EntityId $userId): ?UserProfile
    {
        UserId::assert($userId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `about`, `avatar_path`, `banner_path`, `profile_visibility`, '
            . '`about_visibility`, `social_visibility`, `media_visibility`, `updated_at_utc` '
            . 'FROM `forwext_user_profiles` WHERE `user_id` = :user_id',
            ['user_id' => $userId->value()],
        ));
        if ($row === null) {
            return null;
        }

        return new UserProfile(
            $userId,
            (string) $row['about'],
            is_string($row['avatar_path'] ?? null) ? $row['avatar_path'] : null,
            is_string($row['banner_path'] ?? null) ? $row['banner_path'] : null,
            ProfileVisibility::from((string) $row['profile_visibility']),
            ProfileVisibility::from((string) $row['about_visibility']),
            ProfileVisibility::from((string) $row['social_visibility']),
            ProfileVisibility::from((string) $row['media_visibility']),
            $this->loadSocialLinks($userId),
            $this->loadTabs($userId),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    public function save(UserProfile $profile): void
    {
        UserId::assert($profile->userId);
        $this->database->transaction(
            function (TransactionalQueryExecutor $database) use ($profile): void {
                $database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_user_profiles` '
                    . '(`user_id`, `about`, `avatar_path`, `banner_path`, `profile_visibility`, '
                    . '`about_visibility`, `social_visibility`, `media_visibility`, `updated_at_utc`) '
                    . 'VALUES (:user_id, :about, :avatar, :banner, :profile_visibility, '
                    . ':about_visibility, :social_visibility, :media_visibility, :updated_at) '
                    . 'ON DUPLICATE KEY UPDATE '
                    . '`about` = VALUES(`about`), '
                    . '`profile_visibility` = VALUES(`profile_visibility`), '
                    . '`about_visibility` = VALUES(`about_visibility`), '
                    . '`social_visibility` = VALUES(`social_visibility`), '
                    . '`media_visibility` = VALUES(`media_visibility`), '
                    . '`updated_at_utc` = VALUES(`updated_at_utc`)',
                    [
                        'user_id' => $profile->userId->value(),
                        'about' => $profile->about,
                        'avatar' => $profile->avatarPath,
                        'banner' => $profile->bannerPath,
                        'profile_visibility' => $profile->profileVisibility->value,
                        'about_visibility' => $profile->aboutVisibility->value,
                        'social_visibility' => $profile->socialVisibility->value,
                        'media_visibility' => $profile->mediaVisibility->value,
                        'updated_at' => self::format($profile->updatedAt),
                    ],
                ));

                $this->replaceSocialLinks($database, $profile);
                $this->replaceTabs($database, $profile);
            },
        );
    }

    public function updateMedia(EntityId $userId, ProfileMediaKind $kind, ?string $path): void
    {
        UserId::assert($userId);
        $column = $kind === ProfileMediaKind::Avatar ? 'avatar_path' : 'banner_path';
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_user_profiles` '
            . '(`user_id`, `about`, `profile_visibility`, `about_visibility`, '
            . '`social_visibility`, `media_visibility`, `updated_at_utc`, `' . $column . '`) '
            . 'VALUES (:user_id, \'\', \'public\', \'public\', \'public\', \'public\', UTC_TIMESTAMP(6), :path) '
            . 'ON DUPLICATE KEY UPDATE `' . $column . '` = VALUES(`' . $column . '`), '
            . '`updated_at_utc` = UTC_TIMESTAMP(6)',
            ['user_id' => $userId->value(), 'path' => $path],
        ));
    }

    /** @return list<SocialLink> */
    private function loadSocialLinks(EntityId $userId): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `link_key`, `url`, `visibility`, `sort_order` '
            . 'FROM `forwext_user_social_links` WHERE `user_id` = :user_id '
            . 'ORDER BY `sort_order`, `link_key`',
            ['user_id' => $userId->value()],
        ));

        $links = [];
        foreach ($rows as $row) {
            $links[] = new SocialLink(
                (string) $row['link_key'],
                (string) $row['url'],
                ProfileVisibility::from((string) $row['visibility']),
                (int) $row['sort_order'],
            );
        }
        return $links;
    }

    /** @return list<ProfileTab> */
    private function loadTabs(EntityId $userId): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `tab_key`, `enabled`, `visibility`, `sort_order` '
            . 'FROM `forwext_user_profile_tabs` WHERE `user_id` = :user_id '
            . 'ORDER BY `sort_order`, `tab_key`',
            ['user_id' => $userId->value()],
        ));
        if ($rows === []) {
            return ProfileService::defaultTabs();
        }

        $tabs = [];
        foreach ($rows as $row) {
            $tabs[] = new ProfileTab(
                (string) $row['tab_key'],
                (int) $row['enabled'] === 1,
                ProfileVisibility::from((string) $row['visibility']),
                (int) $row['sort_order'],
            );
        }
        return $tabs;
    }

    private function replaceSocialLinks(
        TransactionalQueryExecutor $database,
        UserProfile $profile,
    ): void {
        $database->execute(new CompiledQuery(
            'DELETE FROM `forwext_user_social_links` WHERE `user_id` = :user_id',
            ['user_id' => $profile->userId->value()],
        ));

        foreach ($profile->socialLinks as $link) {
            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_social_links` '
                . '(`user_id`, `link_key`, `url`, `visibility`, `sort_order`) '
                . 'VALUES (:user_id, :key, :url, :visibility, :sort_order)',
                [
                    'user_id' => $profile->userId->value(),
                    'key' => $link->key,
                    'url' => $link->url,
                    'visibility' => $link->visibility->value,
                    'sort_order' => $link->sortOrder,
                ],
            ));
        }
    }

    private function replaceTabs(
        TransactionalQueryExecutor $database,
        UserProfile $profile,
    ): void {
        $database->execute(new CompiledQuery(
            'DELETE FROM `forwext_user_profile_tabs` WHERE `user_id` = :user_id',
            ['user_id' => $profile->userId->value()],
        ));

        foreach ($profile->tabs as $tab) {
            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_profile_tabs` '
                . '(`user_id`, `tab_key`, `enabled`, `visibility`, `sort_order`) '
                . 'VALUES (:user_id, :key, :enabled, :visibility, :sort_order)',
                [
                    'user_id' => $profile->userId->value(),
                    'key' => $tab->key,
                    'enabled' => $tab->enabled ? 1 : 0,
                    'visibility' => $tab->visibility->value,
                    'sort_order' => $tab->sortOrder,
                ],
            ));
        }
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC'),
        );
        if (!$date instanceof DateTimeImmutable) {
            throw new ProfileException('Stored profile timestamp is invalid.');
        }
        return $date;
    }
}
