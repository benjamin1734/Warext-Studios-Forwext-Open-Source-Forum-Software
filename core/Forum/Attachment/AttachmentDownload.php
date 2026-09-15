<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

final readonly class AttachmentDownload
{
    public function __construct(
        public string $contents,
        public string $mediaType,
        public string $filename,
        public bool $thumbnail,
    ) {
    }
}
