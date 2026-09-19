<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class RewardGrantRequest
{
    public function __construct(
        public EntityId $recipientUserId,
        public string $sourceType,
        public string $sourceId,
        public string $rewardKey,
        public int $units = 1,
    ) {
        UserId::assert($this->recipientUserId);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->sourceType) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $this->sourceId) !== 1
            || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->rewardKey) !== 1
            || $this->units < 1
            || $this->units > 1_000_000
        ) {
            throw new InvalidArgumentException('Reward grant request is invalid.');
        }
    }
}
