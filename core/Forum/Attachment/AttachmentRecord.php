<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class AttachmentRecord
{
    public function __construct(
        public EntityId $attachmentId,
        public EntityId $ownerUserId,
        public EntityId $forumNodeId,
        public ?EntityId $postId,
        public AttachmentFilename $filename,
        public string $mediaType,
        public string $extension,
        public int $sizeBytes,
        public string $sha256,
        public string $storagePath,
        public ?string $thumbnailPath,
        public ?int $imageWidth,
        public ?int $imageHeight,
        public bool $metadataStripped,
        public AttachmentState $state,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $attachedAt,
    ) {
    }

    public function isTemporary(): bool
    {
        return $this->state === AttachmentState::Temporary;
    }
}
