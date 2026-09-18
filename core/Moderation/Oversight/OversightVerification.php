<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

final readonly class OversightVerification
{
    public function __construct(
        public bool $valid,
        public int $checkedEntries,
        public int $lastSequence,
        public string $lastHash,
        public ?string $error = null,
    ) {
    }
}
