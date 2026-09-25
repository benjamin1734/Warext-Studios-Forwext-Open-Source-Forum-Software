<?php

declare(strict_types=1);

namespace Forwext\Tools\Addon;

final readonly class AddonBuildResult
{
    public function __construct(public string $path, public string $checksum, public int $fileCount)
    {
    }
}
