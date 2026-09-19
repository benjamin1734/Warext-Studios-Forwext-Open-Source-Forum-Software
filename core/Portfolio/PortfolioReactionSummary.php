<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

final readonly class PortfolioReactionSummary
{
    /** @param array<string,int> $counts */
    public function __construct(
        public int $total,
        public int $score,
        public array $counts,
    ) {
    }
}
