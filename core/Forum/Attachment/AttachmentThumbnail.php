<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

final readonly class AttachmentThumbnail
{
    public function __construct(
        public string $contents,
        public string $mediaType,
        public string $extension,
        public int $width,
        public int $height,
    ) {
    }
}
