<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

final readonly class ReferralAnalytics
{
    public function __construct(
        public int $clicks,
        public int $attributed,
        public int $review,
        public int $qualified,
        public int $rejected,
        public int $rewardUnits,
    ) {
    }
}
