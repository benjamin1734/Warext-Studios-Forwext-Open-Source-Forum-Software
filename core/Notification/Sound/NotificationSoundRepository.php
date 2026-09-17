<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Sound;

use Forwext\Core\Domain\Entity\EntityId;

interface NotificationSoundRepository
{
    public function findSettings(EntityId $userId): ?NotificationSoundSettings;
    public function saveSettings(NotificationSoundSettings $settings): void;
    /** @return list<NotificationSoundCategorySetting> */
    public function categorySettings(EntityId $userId): array;
    public function findCategorySetting(EntityId $userId, string $categoryKey): ?NotificationSoundCategorySetting;
    public function saveCategorySetting(NotificationSoundCategorySetting $setting): void;
    public function deleteCategorySetting(EntityId $userId, string $categoryKey): void;
}
