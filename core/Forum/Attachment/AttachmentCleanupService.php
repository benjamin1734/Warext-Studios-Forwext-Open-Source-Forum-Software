<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use DateTimeImmutable;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Throwable;

final readonly class AttachmentCleanupService
{
    public function __construct(
        private AttachmentRepository $attachments,
        private StorageDriver $storage,
    ) {
    }

    public function cleanupExpired(DateTimeImmutable $now, int $limit = 100): int
    {
        $cleaned = 0;
        foreach ($this->attachments->expiredTemporary($now, $limit) as $record) {
            try {
                if ($record->thumbnailPath !== null) {
                    $this->storage->delete(
                        StoragePath::fromString($record->thumbnailPath),
                        StorageVisibility::Private,
                    );
                }
                $this->storage->delete(
                    StoragePath::fromString($record->storagePath),
                    StorageVisibility::Private,
                );
            } catch (Throwable) {
                // Keep the metadata row when storage cleanup fails so a later maintenance pass can retry.
                continue;
            }

            $this->attachments->deleteTemporary($record->attachmentId, $record->ownerUserId);
            ++$cleaned;
        }

        return $cleaned;
    }
}
