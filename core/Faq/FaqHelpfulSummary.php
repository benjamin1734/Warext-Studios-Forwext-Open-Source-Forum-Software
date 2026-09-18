<?php

declare(strict_types=1);

namespace Forwext\Core\Faq;

final readonly class FaqHelpfulSummary
{
    public function __construct(
        public int $helpful,
        public int $notHelpful,
    ) {
        if ($this->helpful < 0 || $this->notHelpful < 0) {
            throw new \InvalidArgumentException('FAQ helpful counters cannot be negative.');
        }
    }

    public function total(): int
    {
        return $this->helpful + $this->notHelpful;
    }

    public function ratio(): ?float
    {
        return $this->total() === 0 ? null : $this->helpful / $this->total();
    }
}
