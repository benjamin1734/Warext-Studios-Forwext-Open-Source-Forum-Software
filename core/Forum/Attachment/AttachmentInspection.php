<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

final readonly class AttachmentInspection
{
    public function __construct(
        public string $contents,
        public string $mediaType,
        public string $extension,
        public int $sizeBytes,
        public string $sha256,
        public ?int $imageWidth,
        public ?int $imageHeight,
        public bool $metadataStripped,
    ) {
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mediaType, 'image/');
    }
}
