<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Throwable;

final readonly class ProfileMediaService
{
    public function __construct(
        private ProfileStore $profiles,
        private StorageDriver $storage,
        private ProfileAccessPolicy $accessPolicy = new OwnerSafeProfileAccessPolicy(),
        private int $avatarMaxBytes = 8_388_608,
        private int $bannerMaxBytes = 16_777_216,
    ) {
        if ($avatarMaxBytes < 1 || $bannerMaxBytes < 1) {
            throw new ProfileException('Profile media limits must be positive.');
        }
    }

    public function replace(
        EntityId $userId,
        EntityId $actorId,
        ProfileMediaKind $kind,
        string $contents,
    ): string {
        UserId::assert($userId);
        UserId::assert($actorId);
        $this->assertCanEdit($userId, $actorId);

        $limit = $kind === ProfileMediaKind::Avatar
            ? $this->avatarMaxBytes
            : $this->bannerMaxBytes;
        $image = $this->inspect($contents, $kind, $limit);
        $current = $this->profiles->find($userId);
        $oldPath = $kind === ProfileMediaKind::Avatar
            ? $current?->avatarPath
            : $current?->bannerPath;

        $relativePath = sprintf(
            'profiles/%s/%s/%s.%s',
            $userId->value(),
            $kind->value,
            hash('sha256', $contents),
            $image['extension'],
        );
        $path = StoragePath::fromString($relativePath);

        $this->storage->put(
            $path,
            $contents,
            StorageVisibility::Private,
            $image['mime'],
        );

        try {
            $this->profiles->updateMedia($userId, $kind, $relativePath);
        } catch (Throwable $exception) {
            try {
                $this->storage->delete($path, StorageVisibility::Private);
            } catch (Throwable) {
                // The DB reference was not changed; maintenance can remove an orphaned staged object.
            }
            throw $exception;
        }

        if ($oldPath !== null && $oldPath !== $relativePath) {
            try {
                $this->storage->delete(
                    StoragePath::fromString($oldPath),
                    StorageVisibility::Private,
                );
            } catch (Throwable) {
                // The new DB reference is authoritative; old-object cleanup is best effort.
            }
        }

        return $relativePath;
    }

    public function read(
        EntityId $userId,
        ?EntityId $viewerId,
        ProfileMediaKind $kind,
    ): ?ProfileMedia {
        UserId::assert($userId);
        if ($viewerId !== null) {
            UserId::assert($viewerId);
        }

        $profile = $this->profiles->find($userId);
        if ($profile === null || !$this->accessPolicy->canViewSection(
            $profile,
            $profile->mediaVisibility,
            $viewerId,
        )) {
            return null;
        }

        $relativePath = $kind === ProfileMediaKind::Avatar
            ? $profile->avatarPath
            : $profile->bannerPath;
        if ($relativePath === null) {
            return null;
        }

        $contents = $this->storage->read(
            StoragePath::fromString($relativePath),
            StorageVisibility::Private,
        );
        $image = $this->inspect(
            $contents,
            $kind,
            $kind === ProfileMediaKind::Avatar ? $this->avatarMaxBytes : $this->bannerMaxBytes,
        );

        return new ProfileMedia($contents, $image['mime']);
    }

    public function remove(
        EntityId $userId,
        EntityId $actorId,
        ProfileMediaKind $kind,
    ): bool {
        UserId::assert($userId);
        UserId::assert($actorId);
        $this->assertCanEdit($userId, $actorId);

        $current = $this->profiles->find($userId);
        $oldPath = $kind === ProfileMediaKind::Avatar
            ? $current?->avatarPath
            : $current?->bannerPath;
        if ($oldPath === null) {
            return false;
        }

        $this->profiles->updateMedia($userId, $kind, null);

        try {
            $this->storage->delete(
                StoragePath::fromString($oldPath),
                StorageVisibility::Private,
            );
        } catch (Throwable) {
            // The reference is already removed; cleanup can be retried by maintenance.
        }

        return true;
    }

    private function assertCanEdit(EntityId $userId, EntityId $actorId): void
    {
        if (!$this->accessPolicy->canEdit($userId, $actorId)) {
            throw new ProfileException('Profile media change is not permitted.');
        }
    }

    /** @return array{mime:string,extension:string,width:int,height:int} */
    private function inspect(string $contents, ProfileMediaKind $kind, int $maxBytes): array
    {
        $size = strlen($contents);
        if ($size < 1 || $size > $maxBytes) {
            throw new ProfileException('Profile media exceeds the allowed size.');
        }

        $info = @getimagesizefromstring($contents);
        if (!is_array($info)) {
            throw new ProfileException('Profile media must be a valid image.');
        }

        $mime = $info['mime'] ?? null;
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!is_string($mime) || !isset($extensions[$mime])) {
            throw new ProfileException('Profile media must be JPEG, PNG or WebP.');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $maxWidth = $kind === ProfileMediaKind::Avatar ? 4096 : 8192;
        $maxHeight = 4096;
        if ($width < 1 || $height < 1 || $width > $maxWidth || $height > $maxHeight) {
            throw new ProfileException('Profile media dimensions are invalid.');
        }

        return [
            'mime' => $mime,
            'extension' => $extensions[$mime],
            'width' => $width,
            'height' => $height,
        ];
    }
}
