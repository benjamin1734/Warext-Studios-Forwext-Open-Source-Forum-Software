<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use InvalidArgumentException;

final readonly class AttachmentQuotaPolicy
{
    public function __construct(
        public int $maxFileBytes = 26_214_400,
        public int $maxTemporaryBytes = 104_857_600,
        public int $maxTemporaryCount = 20,
        public int $maxStoredBytes = 536_870_912,
        public int $maxImagePixels = 40_000_000,
        public int $temporaryTtlSeconds = 86_400,
        public int $thumbnailMaxWidth = 480,
        public int $thumbnailMaxHeight = 480,
    ) {
        foreach ([
            $maxFileBytes, $maxTemporaryBytes, $maxTemporaryCount, $maxStoredBytes,
            $maxImagePixels, $temporaryTtlSeconds, $thumbnailMaxWidth, $thumbnailMaxHeight,
        ] as $value) {
            if ($value < 1) throw new InvalidArgumentException('Attachment quota values must be positive.');
        }
        if ($maxFileBytes > $maxTemporaryBytes || $maxTemporaryBytes > $maxStoredBytes) {
            throw new InvalidArgumentException('Attachment byte quotas must be ordered from per-file to stored total.');
        }
    }
}
