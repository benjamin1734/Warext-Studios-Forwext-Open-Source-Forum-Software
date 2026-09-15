<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface AttachmentRepository
{
    public function find(EntityId $attachmentId): ?AttachmentRecord;

    public function usageForUser(EntityId $userId): AttachmentUsage;

    public function createTemporary(AttachmentRecord $attachment): void;

    public function finalize(
        EntityId $attachmentId,
        EntityId $ownerUserId,
        EntityId $postId,
        string $storagePath,
        ?string $thumbnailPath,
        DateTimeImmutable $attachedAt,
    ): void;

    /** @return list<AttachmentRecord> */
    public function expiredTemporary(DateTimeImmutable $before, int $limit = 100): array;

    public function deleteTemporary(EntityId $attachmentId, EntityId $ownerUserId): void;
}
