<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class PollOptionResult
{
    /** @param list<EntityId|null> $voterUserIds */
    public function __construct(
        public PollOption $option,
        public int $voteCount,
        public array $voterUserIds = [],
    ) {
        if ($this->voteCount < 0 || count($this->voterUserIds) > $this->voteCount) {
            throw new InvalidArgumentException('Poll option result counts are invalid.');
        }
    }
}
