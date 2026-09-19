<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

final readonly class GiveawayEligibilityDecision
{
    /** @param list<string> $reasons */
    public function __construct(
        public bool $eligible,
        public array $reasons,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, []);
    }

    /** @param list<string> $reasons */
    public static function deny(array $reasons): self
    {
        return new self(false, array_values(array_unique($reasons)));
    }
}
