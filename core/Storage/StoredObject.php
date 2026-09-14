<?php

declare(strict_types=1);

namespace Forwext\Core\Storage;

final readonly class StoredObject
{
    public function __construct(
        public StoragePath $path,
        public StorageVisibility $visibility,
        public int $size,
        public string $sha256,
        public ?string $contentType = null,
    ) {
        if ($size < 0 || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw new StorageException('Stored object metadata is invalid.');
        }
        self::validateContentType($contentType);
    }

    public static function validateContentType(?string $contentType): void
    {
        if ($contentType !== null && (
            $contentType === ''
            || strlen($contentType) > 255
            || str_contains($contentType, "\r")
            || str_contains($contentType, "\n")
            || str_contains($contentType, "\0")
        )) {
            throw new StorageException('Stored object content type is invalid.');
        }
    }
}
