<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

final readonly class ReportGroupMatch
{
    public function __construct(
        public ReportGroup $group,
        public bool $created,
    ) {
    }
}
