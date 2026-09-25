<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

final readonly class AddonSignatureVerificationResult
{
    public function __construct(
        public AddonSignatureTrust $trust,
        public string $artifactChecksum,
        public ?string $keyId,
    ) {
    }
}
