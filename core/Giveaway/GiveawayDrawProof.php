<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

final readonly class GiveawayDrawProof
{
    public function __construct(
        public GiveawayDraw $draw,
        public bool $verified,
        public bool $current,
    ) {
    }
}
