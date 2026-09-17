<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Sound;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Notification\NotificationException;

final readonly class NotificationSoundService
{
    public function __construct(
        private NotificationSoundRepository $repository,
        private NotificationSoundPermissionResolver $permissions,
        private NotificationSoundCatalog $catalog,
    ) {
    }

    public function settings(EntityId $userId, ?DateTimeImmutable $now = null): NotificationSoundSettings
    {
        $this->require($userId, NotificationSoundPermission::View);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $stored = $this->repository->findSettings($userId) ?? NotificationSoundSettings::defaults($userId, $now);
        if ($this->catalog->supports($stored->defaultSoundKey)) return $stored;

        return new NotificationSoundSettings(
            $stored->userId,
            $stored->muted,
            $stored->volume,
            $this->catalog->defaultKey(),
            $stored->updatedAt,
        );
    }

    /** @return list<NotificationSoundCategorySetting> */
    public function categorySettings(EntityId $userId): array
    {
        $this->require($userId, NotificationSoundPermission::View);
        $settings = [];
        foreach ($this->repository->categorySettings($userId) as $setting) {
            $settings[] = $setting->soundKey === null || $this->catalog->supports($setting->soundKey)
                ? $setting
                : new NotificationSoundCategorySetting(
                    $setting->userId,
                    $setting->categoryKey,
                    $setting->enabled,
                    null,
                    $setting->updatedAt,
                );
        }
        return $settings;
    }

    public function updateSettings(
        EntityId $userId,
        bool $muted,
        int $volume,
        string $defaultSoundKey,
        ?DateTimeImmutable $now = null,
    ): NotificationSoundSettings {
        $this->require($userId, NotificationSoundPermission::Manage);
        $this->catalog->require($defaultSoundKey);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $settings = new NotificationSoundSettings(
            $userId,
            $muted,
            $volume,
            $defaultSoundKey,
            $now->setTimezone(new DateTimeZone('UTC')),
        );
        $this->repository->saveSettings($settings);
        return $settings;
    }

    public function setCategory(
        EntityId $userId,
        string $categoryKey,
        bool $enabled,
        ?string $soundKey,
        ?DateTimeImmutable $now = null,
    ): NotificationSoundCategorySetting {
        $this->require($userId, NotificationSoundPermission::Manage);
        if ($soundKey !== null) $this->catalog->require($soundKey);
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $setting = new NotificationSoundCategorySetting(
            $userId,
            $categoryKey,
            $enabled,
            $soundKey,
            $now->setTimezone(new DateTimeZone('UTC')),
        );
        $this->repository->saveCategorySetting($setting);
        return $setting;
    }

    public function resetCategory(EntityId $userId, string $categoryKey): void
    {
        $this->require($userId, NotificationSoundPermission::Manage);
        new NotificationSoundCategorySetting(
            $userId,
            $categoryKey,
            true,
            null,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $this->repository->deleteCategorySetting($userId, $categoryKey);
    }

    public function playbackPlan(EntityId $userId, string $categoryKey, ?DateTimeImmutable $now = null): NotificationSoundPlaybackPlan
    {
        UserId::assert($userId);
        $this->assertCategoryKey($categoryKey);
        $this->require($userId, NotificationSoundPermission::View);
        $settings = $this->settings($userId, $now);
        if ($settings->muted || $settings->volume === 0) return NotificationSoundPlaybackPlan::skip('muted');

        $category = $this->repository->findCategorySetting($userId, $categoryKey);
        if ($category !== null && !$category->enabled) return NotificationSoundPlaybackPlan::skip('category_disabled');

        $soundKey = $category?->soundKey;
        if ($soundKey === null || !$this->catalog->supports($soundKey)) $soundKey = $settings->defaultSoundKey;
        return NotificationSoundPlaybackPlan::play($soundKey, $settings->volume);
    }

    private function assertCategoryKey(string $categoryKey): void
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $categoryKey) !== 1) {
            throw new \InvalidArgumentException('Notification category key is invalid.');
        }
    }

    /** @return array<string, string> */
    public function presets(): array
    {
        return $this->catalog->all();
    }

    private function require(EntityId $userId, NotificationSoundPermission $permission): void
    {
        UserId::assert($userId);
        if (!$this->permissions->allows($userId, $permission)) {
            throw new NotificationException('Notification sound preference is unavailable.');
        }
    }
}
