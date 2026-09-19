<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class GiveawayDrawCandidate
{
    public function __construct(
        public EntityId $entryId,
        public EntityId $userId,
        public int $weight,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->entryId->value()) !== 1) {
            throw new InvalidArgumentException('Giveaway draw candidate entry id is invalid.');
        }
        UserId::assert($this->userId);
        if ($this->weight < 1 || $this->weight > 1000) {
            throw new InvalidArgumentException('Giveaway draw candidate weight is invalid.');
        }
    }
}
