<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

final readonly class AddonCapabilityWarning
{
    public function __construct(
        public AddonCapability $capability,
        public AddonCapabilityRisk $risk,
        public string $message,
    ) {
    }
}
