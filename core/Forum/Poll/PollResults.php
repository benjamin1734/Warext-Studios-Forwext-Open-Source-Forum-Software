<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class PollResults
{
    /** @param list<PollOptionResult> $options */
    public function __construct(
        public EntityId $pollId,
        public int $totalVoters,
        public bool $closed,
        public array $options,
    ) {
        PollId::assert($this->pollId);
        if ($this->totalVoters < 0) {
            throw new InvalidArgumentException('Poll total voter count cannot be negative.');
        }
    }
}
