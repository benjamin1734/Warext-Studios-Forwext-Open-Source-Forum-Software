<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

final readonly class GiveawayDrawSelection
{
    public function __construct(
        public string $populationHash,
        public int $participantCount,
        public int $totalWeight,
        public int $selectedTicket,
        public GiveawayEntry $winner,
    ) {
    }
}
