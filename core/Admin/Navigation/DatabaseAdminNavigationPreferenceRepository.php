<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Navigation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Entity\EntityId;
use JsonException;

final readonly class DatabaseAdminNavigationPreferenceRepository implements AdminNavigationPreferenceRepository
{
    public function __construct(private DatabaseConnection $database)
    {
    }

    public function load(EntityId $userId): AdminNavigationPreferences
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT favorites_json,recent_json FROM forwext_admin_navigation_preferences WHERE user_id=:user_id',
            ['user_id'=>$userId->value()],
        ));
        if ($row === null) {
            return new AdminNavigationPreferences();
        }

        try {
            $favorites = json_decode((string) ($row['favorites_json'] ?? '[]'), true, 64, JSON_THROW_ON_ERROR);
            $recent = json_decode((string) ($row['recent_json'] ?? '[]'), true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($favorites) || !array_is_list($favorites) || !is_array($recent) || !array_is_list($recent)) {
                return new AdminNavigationPreferences();
            }

            return new AdminNavigationPreferences($favorites, $recent);
        } catch (JsonException|\InvalidArgumentException) {
            return new AdminNavigationPreferences();
        }
    }

    public function save(
        EntityId $userId,
        AdminNavigationPreferences $preferences,
        DateTimeImmutable $updatedAt,
    ): void {
        $updatedAt = $updatedAt->setTimezone(new DateTimeZone('UTC'));

        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_admin_navigation_preferences '
            . '(user_id,favorites_json,recent_json,updated_at_utc) '
            . 'VALUES (:user_id,:favorites_json,:recent_json,:updated_at_utc) '
            . 'ON DUPLICATE KEY UPDATE favorites_json=VALUES(favorites_json),'
            . 'recent_json=VALUES(recent_json),updated_at_utc=VALUES(updated_at_utc)',
            [
                'user_id'=>$userId->value(),
                'favorites_json'=>json_encode($preferences->favorites(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'recent_json'=>json_encode($preferences->recent(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at_utc'=>$updatedAt->format('Y-m-d H:i:s.u'),
            ],
        ));
    }
}
