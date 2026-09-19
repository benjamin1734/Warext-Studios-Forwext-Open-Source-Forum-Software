<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

final readonly class ReferralCapture
{
    public function __construct(
        public string $code,
        public int $maxAgeSeconds,
    ) {
    }
}
