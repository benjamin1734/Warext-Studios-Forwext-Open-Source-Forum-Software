<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Sound;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseNotificationSoundRepository implements NotificationSoundRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function findSettings(EntityId $userId): ?NotificationSoundSettings
    {
        UserId::assert($userId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM `forwext_notification_sound_settings` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId->value()],
        ));
        return $row === null ? null : $this->hydrateSettings($row);
    }

    public function saveSettings(NotificationSoundSettings $settings): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_notification_sound_settings` '
            . '(`user_id`,`muted`,`volume`,`default_sound_key`,`updated_at_utc`) '
            . 'VALUES (:user_id,:muted,:volume,:default_sound_key,:updated_at_utc) '
            . 'ON DUPLICATE KEY UPDATE `muted`=VALUES(`muted`), `volume`=VALUES(`volume`), '
            . '`default_sound_key`=VALUES(`default_sound_key`), `updated_at_utc`=VALUES(`updated_at_utc`)',
            [
                'user_id' => $settings->userId->value(),
                'muted' => $settings->muted ? 1 : 0,
                'volume' => $settings->volume,
                'default_sound_key' => $settings->defaultSoundKey,
                'updated_at_utc' => $this->format($settings->updatedAt),
            ],
        ));
    }

    public function categorySettings(EntityId $userId): array
    {
        UserId::assert($userId);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM `forwext_notification_sound_categories` WHERE `user_id` = :user_id ORDER BY `category_key` ASC',
            ['user_id' => $userId->value()],
        ));
        return array_map(fn (array $row): NotificationSoundCategorySetting => $this->hydrateCategory($row), $rows);
    }

    public function findCategorySetting(EntityId $userId, string $categoryKey): ?NotificationSoundCategorySetting
    {
        UserId::assert($userId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM `forwext_notification_sound_categories` '
            . 'WHERE `user_id` = :user_id AND `category_key` = :category_key LIMIT 1',
            ['user_id' => $userId->value(), 'category_key' => $categoryKey],
        ));
        return $row === null ? null : $this->hydrateCategory($row);
    }

    public function saveCategorySetting(NotificationSoundCategorySetting $setting): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_notification_sound_categories` '
            . '(`user_id`,`category_key`,`enabled`,`sound_key`,`updated_at_utc`) '
            . 'VALUES (:user_id,:category_key,:enabled,:sound_key,:updated_at_utc) '
            . 'ON DUPLICATE KEY UPDATE `enabled`=VALUES(`enabled`), `sound_key`=VALUES(`sound_key`), '
            . '`updated_at_utc`=VALUES(`updated_at_utc`)',
            [
                'user_id' => $setting->userId->value(),
                'category_key' => $setting->categoryKey,
                'enabled' => $setting->enabled ? 1 : 0,
                'sound_key' => $setting->soundKey,
                'updated_at_utc' => $this->format($setting->updatedAt),
            ],
        ));
    }

    public function deleteCategorySetting(EntityId $userId, string $categoryKey): void
    {
        UserId::assert($userId);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_notification_sound_categories` WHERE `user_id` = :user_id AND `category_key` = :category_key',
            ['user_id' => $userId->value(), 'category_key' => $categoryKey],
        ));
    }

    /** @param array<string, mixed> $row */
    private function hydrateSettings(array $row): NotificationSoundSettings
    {
        return new NotificationSoundSettings(
            UserId::fromStored((string) $row['user_id']),
            (bool) ($row['muted'] ?? false),
            (int) ($row['volume'] ?? 65),
            (string) ($row['default_sound_key'] ?? 'soft'),
            $this->parseDate((string) $row['updated_at_utc']),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateCategory(array $row): NotificationSoundCategorySetting
    {
        return new NotificationSoundCategorySetting(
            UserId::fromStored((string) $row['user_id']),
            (string) $row['category_key'],
            (bool) ($row['enabled'] ?? true),
            isset($row['sound_key']) && is_string($row['sound_key']) && $row['sound_key'] !== '' ? $row['sound_key'] : null,
            $this->parseDate((string) $row['updated_at_utc']),
        );
    }

    private function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new \RuntimeException('Stored notification sound timestamp is invalid.');
        }
        return $date;
    }
}
