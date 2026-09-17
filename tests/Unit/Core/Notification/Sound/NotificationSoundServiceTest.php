<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Notification\Sound;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Notification\NotificationException;
use Forwext\Core\Notification\Sound\NotificationSoundCatalog;
use Forwext\Core\Notification\Sound\NotificationSoundCategorySetting;
use Forwext\Core\Notification\Sound\NotificationSoundPermission;
use Forwext\Core\Notification\Sound\NotificationSoundPermissionResolver;
use Forwext\Core\Notification\Sound\NotificationSoundRepository;
use Forwext\Core\Notification\Sound\NotificationSoundService;
use Forwext\Core\Notification\Sound\NotificationSoundSettings;
use PHPUnit\Framework\TestCase;

final class NotificationSoundServiceTest extends TestCase
{
    public function testDefaultsAndGlobalUpdateAreValidated(): void
    {
        $repository = new SoundMemoryRepository();
        $service = $this->service($repository);
        $user = UserId::fromStored(str_repeat('a', 32));
        $now = new DateTimeImmutable('2026-09-17 13:00:00', new DateTimeZone('UTC'));

        $defaults = $service->settings($user, $now);
        self::assertFalse($defaults->muted);
        self::assertSame(65, $defaults->volume);
        self::assertSame('soft', $defaults->defaultSoundKey);

        $updated = $service->updateSettings($user, false, 42, 'chime', $now);
        self::assertSame(42, $updated->volume);
        self::assertSame('chime', $repository->settings?->defaultSoundKey);
    }

    public function testMuteAndCategoryDisableSuppressPlayback(): void
    {
        $repository = new SoundMemoryRepository();
        $service = $this->service($repository);
        $user = UserId::fromStored(str_repeat('b', 32));
        $now = new DateTimeImmutable('2026-09-17 13:00:00', new DateTimeZone('UTC'));

        $service->updateSettings($user, true, 70, 'soft', $now);
        self::assertSame('muted', $service->playbackPlan($user, 'forum', $now)->reason);

        $service->updateSettings($user, false, 70, 'soft', $now);
        $service->setCategory($user, 'forum', false, 'pulse', $now);
        self::assertSame('category_disabled', $service->playbackPlan($user, 'forum', $now)->reason);
    }

    public function testCategoryPresetOverridesGlobalAndResetRestoresInheritance(): void
    {
        $repository = new SoundMemoryRepository();
        $service = $this->service($repository);
        $user = UserId::fromStored(str_repeat('c', 32));
        $now = new DateTimeImmutable('2026-09-17 13:00:00', new DateTimeZone('UTC'));

        $service->updateSettings($user, false, 55, 'soft', $now);
        $service->setCategory($user, 'social', true, 'minimal', $now);
        $plan = $service->playbackPlan($user, 'social', $now);
        self::assertTrue($plan->enabled);
        self::assertSame('minimal', $plan->soundKey);
        self::assertSame(0.55, $plan->volume);

        $service->resetCategory($user, 'social');
        self::assertSame('soft', $service->playbackPlan($user, 'social', $now)->soundKey);
    }

    public function testUnsupportedPresetAndPermissionFailureFailClosed(): void
    {
        $repository = new SoundMemoryRepository();
        $service = $this->service($repository);
        $user = UserId::fromStored(str_repeat('d', 32));

        $this->expectException(\InvalidArgumentException::class);
        $service->updateSettings($user, false, 50, 'https://evil.example/sound.mp3');
    }

    public function testManagePermissionIsRequiredForWrites(): void
    {
        $repository = new SoundMemoryRepository();
        $permissions = new SoundPermissionResolver();
        $permissions->manage = false;
        $service = new NotificationSoundService($repository, $permissions, NotificationSoundCatalog::coreDefaults());
        $user = UserId::fromStored(str_repeat('e', 32));

        $this->expectException(NotificationException::class);
        $service->setCategory($user, 'forum', true, null);
    }

    private function service(SoundMemoryRepository $repository): NotificationSoundService
    {
        return new NotificationSoundService($repository, new SoundPermissionResolver(), NotificationSoundCatalog::coreDefaults());
    }
}

final class SoundPermissionResolver implements NotificationSoundPermissionResolver
{
    public bool $view = true;
    public bool $manage = true;
    public function allows(EntityId $userId, NotificationSoundPermission $permission): bool
    {
        return $permission === NotificationSoundPermission::View ? $this->view : $this->manage;
    }
}

final class SoundMemoryRepository implements NotificationSoundRepository
{
    public ?NotificationSoundSettings $settings = null;
    /** @var array<string, NotificationSoundCategorySetting> */ public array $categories = [];
    public function findSettings(EntityId $userId): ?NotificationSoundSettings { return $this->settings; }
    public function saveSettings(NotificationSoundSettings $settings): void { $this->settings = $settings; }
    public function categorySettings(EntityId $userId): array { return array_values($this->categories); }
    public function findCategorySetting(EntityId $userId, string $categoryKey): ?NotificationSoundCategorySetting { return $this->categories[$categoryKey] ?? null; }
    public function saveCategorySetting(NotificationSoundCategorySetting $setting): void { $this->categories[$setting->categoryKey] = $setting; }
    public function deleteCategorySetting(EntityId $userId, string $categoryKey): void { unset($this->categories[$categoryKey]); }
}
