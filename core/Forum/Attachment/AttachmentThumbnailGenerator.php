<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

interface AttachmentThumbnailGenerator
{
    public function generate(AttachmentInspection $inspection): ?AttachmentThumbnail;
}
