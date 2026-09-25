<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use InvalidArgumentException;

final readonly class AddonPackage
{
    public function __construct(
        public AddonManifest $manifest,
        public string $rootPath,
        public string $checksum,
    ) {
        if ($this->rootPath === '' || !str_starts_with($this->rootPath, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Add-on package root must be an absolute path.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->checksum) !== 1) {
            throw new InvalidArgumentException('Add-on package checksum is invalid.');
        }
    }
}
