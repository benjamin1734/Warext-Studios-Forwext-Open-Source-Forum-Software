<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Attachment\AttachmentFilename;
use InvalidArgumentException;

final readonly class BugAttachmentRecord
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $attachmentId,
        public EntityId $reportId,
        public ?EntityId $ownerUserId,
        public AttachmentFilename $filename,
        public string $mediaType,
        public string $extension,
        public int $sizeBytes,
        public string $sha256,
        public string $storagePath,
        public ?int $imageWidth,
        public ?int $imageHeight,
        public bool $metadataStripped,
        DateTimeImmutable $createdAt,
    ) {
        if ($this->ownerUserId !== null) {
            UserId::assert($this->ownerUserId);
        }
        if ($this->sizeBytes < 1 || preg_match('/^[a-f0-9]{64}$/D', $this->sha256) !== 1) {
            throw new InvalidArgumentException('Bug attachment integrity metadata is invalid.');
        }
        if (preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/D', $this->mediaType) !== 1
            || preg_match('/^[a-z0-9]{1,10}$/D', $this->extension) !== 1
            || $this->storagePath === ''
        ) {
            throw new InvalidArgumentException('Bug attachment storage metadata is invalid.');
        }
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
