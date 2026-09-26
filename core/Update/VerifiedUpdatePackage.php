<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

use ZipArchive;

final readonly class VerifiedUpdatePackage
{
    public function __construct(
        public string $path,
        public UpdateManifest $manifest,
    ) {
    }

    public function payload(string $relativePath): string
    {
        $expected = $this->manifest->checksums[$relativePath] ?? null;
        if (!is_string($expected)) {
            throw new UpdateException('Requested update payload is not declared by the manifest.');
        }

        $archive = new ZipArchive();
        if ($archive->open($this->path) !== true) {
            throw new UpdateException('Verified update archive can no longer be opened.');
        }

        try {
            $payload = $archive->getFromName($relativePath);
            if ($payload === false) {
                throw new UpdateException('Verified update payload is missing.');
            }
        } finally {
            $archive->close();
        }

        if (!hash_equals($expected, hash('sha256', $payload))) {
            throw new UpdateException('Verified update payload changed after inspection.');
        }

        return $payload;
    }
}
