<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use InvalidArgumentException;

final readonly class OversightChainState
{
    public function __construct(
        public int $lastSequence,
        public string $lastHash,
    ) {
        if ($this->lastSequence < 0 || preg_match('/^[a-f0-9]{64}$/D', $this->lastHash) !== 1) {
            throw new InvalidArgumentException('Oversight chain state is invalid.');
        }
    }

    public static function genesis(): self
    {
        return new self(0, str_repeat('0', 64));
    }
}
