<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use InvalidArgumentException;

final readonly class AttachmentUsage
{
    public function __construct(
        public int $temporaryCount,
        public int $temporaryBytes,
        public int $storedBytes,
    ) {
        if ($temporaryCount < 0 || $temporaryBytes < 0 || $storedBytes < 0 || $temporaryBytes > $storedBytes) {
            throw new InvalidArgumentException('Attachment usage values are invalid.');
        }
    }
}
