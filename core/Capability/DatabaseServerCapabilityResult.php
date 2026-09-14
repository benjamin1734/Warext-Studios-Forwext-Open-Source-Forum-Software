<?php

declare(strict_types=1);

namespace Forwext\Core\Capability;

final readonly class DatabaseServerCapabilityResult
{
    public function __construct(
        public string $vendor,
        public string $version,
        public bool $recognized,
        public bool $innodbFulltext,
    ) {
    }
}
