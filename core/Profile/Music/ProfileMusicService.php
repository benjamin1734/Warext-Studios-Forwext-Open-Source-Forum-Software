<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Profile\ProfileAccessPolicy;
use Forwext\Core\Profile\ProfileException;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Throwable;

final readonly class ProfileMusicService
{
    public function __construct(
        private ProfileMusicStore $store,
        private StorageDriver $storage,
        private ProfileService $profiles,
        private ProfileAccessPolicy $profileAccess,
        private ProfileMusicPermissionResolver $permissions,
        private ProfileMusicExternalPolicy $externalPolicy,
        private int $uploadMaxBytes = 20_971_520,
        private int $defaultVolume = 70,
    ) {
        if ($uploadMaxBytes < 1 || $uploadMaxBytes > 104_857_600 || $defaultVolume < 0 || $defaultVolume > 100) {
            throw new ProfileException('Profile music runtime limits are invalid.');
        }
    }

    public function settingsOrDefault(EntityId $userId, DateTimeImmutable $now): ProfileMusicSettings
    {
        UserId::assert($userId);
        return $this->store->find($userId)
            ?? ProfileMusicSettings::defaults($userId, $now, $this->defaultVolume);
    }

    public function updatePreferences(
        EntityId $userId,
        EntityId $actorId,
        bool $enabled,
        string $title,
        ProfileVisibility $visibility,
        int $volume,
        bool $muted,
        bool $autoplay,
        bool $loop,
        DateTimeImmutable $now,
    ): ProfileMusicSettings {
        $this->assertOwnerCapability($userId, $actorId, ProfileMusicPermission::Use);
        if ($autoplay && !$this->permissions->allows($userId, ProfileMusicPermission::Autoplay)) {
            throw new ProfileException('Profile music autoplay is not permitted for this user.');
        }

        $current = $this->settingsOrDefault($userId, $now);
        $updated = $this->copy(
            $current,
            enabled: $enabled,
            title: trim($title),
            visibility: $visibility,
            volume: $volume,
            muted: $muted,
            autoplay: $autoplay,
            loop: $loop,
            updatedAt: ProfileMusicSettings::utc($now),
        );
        $this->store->save($updated);
        return $updated;
    }

    public function replaceUpload(
        EntityId $userId,
        EntityId $actorId,
        string $contents,
        DateTimeImmutable $now,
    ): ProfileMusicSettings {
        $this->assertOwnerCapability($userId, $actorId, ProfileMusicPermission::Use);
        if (!$this->permissions->allows($userId, ProfileMusicPermission::Upload)) {
            throw new ProfileException('Profile music upload is not permitted for this user.');
        }

        $payload = $this->inspectUpload($contents);
        $pathString = sprintf(
            'profiles/%s/music/%s.%s',
            $userId->value(),
            hash('sha256', $contents),
            $payload->extension,
        );
        $path = StoragePath::fromString($pathString);
        $current = $this->settingsOrDefault($userId, $now);
        $oldUpload = $current->sourceType === ProfileMusicSourceType::Upload ? $current->uploadPath : null;

        $this->storage->put($path, $contents, StorageVisibility::Private, $payload->contentType);
        $updated = $this->copy(
            $current,
            sourceType: ProfileMusicSourceType::Upload,
            uploadPath: $pathString,
            externalUrl: null,
            updatedAt: ProfileMusicSettings::utc($now),
        );

        try {
            $this->store->save($updated);
        } catch (Throwable $exception) {
            try {
                $this->storage->delete($path, StorageVisibility::Private);
            } catch (Throwable) {
                // Database state stayed authoritative; maintenance can remove an orphaned staged object.
            }
            throw $exception;
        }

        $this->deleteOldUpload($oldUpload, $pathString);
        return $updated;
    }

    public function setExternalSource(
        EntityId $userId,
        EntityId $actorId,
        string $url,
        DateTimeImmutable $now,
    ): ProfileMusicSettings {
        $this->assertOwnerCapability($userId, $actorId, ProfileMusicPermission::Use);
        if (!$this->permissions->allows($userId, ProfileMusicPermission::External)) {
            throw new ProfileException('External profile music is not permitted for this user.');
        }

        $normalized = $this->externalPolicy->normalize($url);
        $current = $this->settingsOrDefault($userId, $now);
        $oldUpload = $current->sourceType === ProfileMusicSourceType::Upload ? $current->uploadPath : null;
        $updated = $this->copy(
            $current,
            sourceType: ProfileMusicSourceType::External,
            uploadPath: null,
            externalUrl: $normalized,
            updatedAt: ProfileMusicSettings::utc($now),
        );
        $this->store->save($updated);
        $this->deleteOldUpload($oldUpload, null);
        return $updated;
    }

    public function clearSource(
        EntityId $userId,
        EntityId $actorId,
        DateTimeImmutable $now,
    ): ProfileMusicSettings {
        $this->assertOwnerCapability($userId, $actorId, ProfileMusicPermission::Use);
        $current = $this->settingsOrDefault($userId, $now);
        $oldUpload = $current->sourceType === ProfileMusicSourceType::Upload ? $current->uploadPath : null;
        $updated = $this->copy(
            $current,
            enabled: false,
            sourceType: null,
            uploadPath: null,
            externalUrl: null,
            updatedAt: ProfileMusicSettings::utc($now),
        );
        $this->store->save($updated);
        $this->deleteOldUpload($oldUpload, null);
        return $updated;
    }

    public function visiblePlayback(
        EntityId $userId,
        ?EntityId $viewerId,
        DateTimeImmutable $now,
    ): ?ProfileMusicSettings {
        UserId::assert($userId);
        if ($viewerId !== null) {
            UserId::assert($viewerId);
        }
        if (!$this->permissions->allows($userId, ProfileMusicPermission::Use)) {
            return null;
        }

        $settings = $this->store->find($userId);
        if (
            $settings === null
            || !$settings->enabled
            || $settings->sourceType === null
            || $settings->moderationStatus !== ProfileMusicModerationStatus::Active
        ) {
            return null;
        }
        if ($settings->sourceType === ProfileMusicSourceType::Upload
            && !$this->permissions->allows($userId, ProfileMusicPermission::Upload)
        ) {
            return null;
        }
        if ($settings->sourceType === ProfileMusicSourceType::External
            && !$this->permissions->allows($userId, ProfileMusicPermission::External)
        ) {
            return null;
        }

        $profile = $this->profiles->getOrDefault($userId, $now);
        if (!$this->profileAccess->canViewSection($profile, $settings->visibility, $viewerId)) {
            return null;
        }
        return $settings;
    }

    public function readUpload(
        EntityId $userId,
        ?EntityId $viewerId,
        DateTimeImmutable $now,
    ): ?ProfileMusicPayload {
        $settings = $this->visiblePlayback($userId, $viewerId, $now);
        if ($settings === null || $settings->sourceType !== ProfileMusicSourceType::Upload || $settings->uploadPath === null) {
            return null;
        }

        $contents = $this->storage->read(
            StoragePath::fromString($settings->uploadPath),
            StorageVisibility::Private,
        );
        $payload = $this->inspectUpload($contents);
        if (!str_ends_with($settings->uploadPath, '.' . $payload->extension)) {
            throw new ProfileException('Stored profile music type does not match its path.');
        }
        return $payload;
    }

    public function externalUrl(
        EntityId $userId,
        ?EntityId $viewerId,
        DateTimeImmutable $now,
    ): ?string {
        $settings = $this->visiblePlayback($userId, $viewerId, $now);
        if ($settings === null || $settings->sourceType !== ProfileMusicSourceType::External || $settings->externalUrl === null) {
            return null;
        }
        return $this->externalPolicy->normalize($settings->externalUrl);
    }

    public function autoplayAllowed(EntityId $userId): bool
    {
        UserId::assert($userId);
        return $this->permissions->allows($userId, ProfileMusicPermission::Autoplay);
    }

    public function moderate(
        EntityId $userId,
        EntityId $actorId,
        bool $blocked,
        ?string $reasonCode,
        DateTimeImmutable $now,
    ): ProfileMusicSettings {
        UserId::assert($userId);
        UserId::assert($actorId);
        if (!$this->permissions->allows($actorId, ProfileMusicPermission::Moderate)) {
            throw new ProfileException('Profile music moderation is not permitted.');
        }

        $current = $this->store->find($userId);
        if ($current === null || $current->sourceType === null) {
            throw new ProfileException('Profile music source does not exist.');
        }
        $at = ProfileMusicSettings::utc($now);
        $action = $blocked ? ProfileMusicModerationAction::Block : ProfileMusicModerationAction::Unblock;
        $reasonCode = $blocked ? $reasonCode : null;
        $event = new ProfileMusicModerationEvent($userId, $actorId, $action, $reasonCode, $at);
        $updated = $this->copy(
            $current,
            moderationStatus: $blocked ? ProfileMusicModerationStatus::Blocked : ProfileMusicModerationStatus::Active,
            moderationReasonCode: $reasonCode,
            moderatedByUserId: $actorId,
            moderatedAt: $at,
            updatedAt: $at,
        );
        $this->store->saveModeration($updated, $event);
        return $updated;
    }

    private function assertOwnerCapability(
        EntityId $userId,
        EntityId $actorId,
        ProfileMusicPermission $permission,
    ): void {
        UserId::assert($userId);
        UserId::assert($actorId);
        if (!$this->profileAccess->canEdit($userId, $actorId) || !$this->permissions->allows($userId, $permission)) {
            throw new ProfileException('Profile music change is not permitted.');
        }
    }

    private function inspectUpload(string $contents): ProfileMusicPayload
    {
        $length = strlen($contents);
        if ($length < 12 || $length > $this->uploadMaxBytes) {
            throw new ProfileException('Profile music upload exceeds the allowed size or is empty.');
        }

        if (str_starts_with($contents, 'ID3')
            || (ord($contents[0]) === 0xff && (ord($contents[1]) & 0xe0) === 0xe0)
        ) {
            return new ProfileMusicPayload($contents, 'audio/mpeg', 'mp3');
        }
        if (substr($contents, 0, 4) === 'OggS') {
            return new ProfileMusicPayload($contents, 'audio/ogg', 'ogg');
        }
        if (substr($contents, 0, 4) === 'RIFF' && substr($contents, 8, 4) === 'WAVE') {
            return new ProfileMusicPayload($contents, 'audio/wav', 'wav');
        }
        if (substr($contents, 4, 4) === 'ftyp' && in_array(substr($contents, 8, 4), ['M4A ', 'M4B '], true)) {
            return new ProfileMusicPayload($contents, 'audio/mp4', 'm4a');
        }

        throw new ProfileException('Profile music upload must be MP3, Ogg, WAV or M4A audio.');
    }

    private function deleteOldUpload(?string $oldPath, ?string $replacement): void
    {
        if ($oldPath === null || $oldPath === $replacement) {
            return;
        }
        try {
            $this->storage->delete(StoragePath::fromString($oldPath), StorageVisibility::Private);
        } catch (Throwable) {
            // New persistent state is authoritative; stale object cleanup is best effort.
        }
    }

    private function copy(
        ProfileMusicSettings $current,
        ?bool $enabled = null,
        ProfileMusicSourceType|null|false $sourceType = false,
        string|null|false $uploadPath = false,
        string|null|false $externalUrl = false,
        ?string $title = null,
        ?ProfileVisibility $visibility = null,
        ?int $volume = null,
        ?bool $muted = null,
        ?bool $autoplay = null,
        ?bool $loop = null,
        ?ProfileMusicModerationStatus $moderationStatus = null,
        string|null|false $moderationReasonCode = false,
        EntityId|null|false $moderatedByUserId = false,
        DateTimeImmutable|null|false $moderatedAt = false,
        ?DateTimeImmutable $updatedAt = null,
    ): ProfileMusicSettings {
        return new ProfileMusicSettings(
            $current->userId,
            $enabled ?? $current->enabled,
            $sourceType === false ? $current->sourceType : $sourceType,
            $uploadPath === false ? $current->uploadPath : $uploadPath,
            $externalUrl === false ? $current->externalUrl : $externalUrl,
            $title ?? $current->title,
            $visibility ?? $current->visibility,
            $volume ?? $current->volume,
            $muted ?? $current->muted,
            $autoplay ?? $current->autoplay,
            $loop ?? $current->loop,
            $moderationStatus ?? $current->moderationStatus,
            $moderationReasonCode === false ? $current->moderationReasonCode : $moderationReasonCode,
            $moderatedByUserId === false ? $current->moderatedByUserId : $moderatedByUserId,
            $moderatedAt === false ? $current->moderatedAt : $moderatedAt,
            $updatedAt ?? $current->updatedAt,
        );
    }
}
