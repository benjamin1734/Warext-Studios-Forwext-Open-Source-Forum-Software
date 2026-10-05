<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

final readonly class OversightCaseDetail
{
    public function __construct(
        public OversightReviewCase $case,
        public OversightEntry $source,
    ) {
        if (!$this->case->sourceAuditId->equals($this->source->sourceAuditId)) {
            throw new \InvalidArgumentException('Oversight case detail source does not match the review case.');
        }
    }
}
