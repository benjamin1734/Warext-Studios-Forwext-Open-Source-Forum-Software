<?php

declare(strict_types=1);

namespace Forwext\Core\Social\Interaction;

final readonly class ReactionSummary
{
    /** @param array<string,int> $counts */
    public function __construct(
        public int $total,
        public int $score,
        public array $counts,
    ) {
    }
}
