<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use InvalidArgumentException;

final readonly class GiveawayPrize
{
    public function __construct(
        public string $title,
        public string $description,
        public int $quantity,
    ) {
        if (trim($this->title) === '' || strlen($this->title) > 180 || preg_match('//u', $this->title) !== 1) {
            throw new InvalidArgumentException('Giveaway prize title is invalid.');
        }
        if (strlen($this->description) > 2000 || preg_match('//u', $this->description) !== 1) {
            throw new InvalidArgumentException('Giveaway prize description is invalid.');
        }
        if ($this->quantity < 1 || $this->quantity > 1_000_000) {
            throw new InvalidArgumentException('Giveaway prize quantity is invalid.');
        }
    }
}
