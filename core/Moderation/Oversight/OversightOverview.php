<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

final readonly class OversightOverview
{
    /**
     * @param list<OversightEntry> $entries
     * @param list<OversightReviewCase> $cases
     * @param list<OversightAnomalyFlag> $flags
     */
    public function __construct(
        public ?OversightVerification $verification,
        public array $entries,
        public array $cases,
        public array $flags,
    ) {
    }
}
