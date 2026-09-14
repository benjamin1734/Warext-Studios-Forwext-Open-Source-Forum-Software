<?php

declare(strict_types=1);

namespace Forwext\Core\Storage\S3;

use Forwext\Core\Storage\StorageVisibility;

final readonly class S3ObjectMetadata
{
    public function __construct(
        public int $size,
        public string $sha256,
        public StorageVisibility $visibility,
        public ?string $contentType = null,
    ) {
    }
}
