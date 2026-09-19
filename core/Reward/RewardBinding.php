<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class RewardBinding
{
    public function __construct(
        public EntityId $bindingId,
        public string $sourceType,
        public EntityId $sourceDefinitionId,
        public string $rewardKey,
        public int $units,
        public bool $active = true,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->bindingId->value()) !== 1
            || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->sourceType) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $this->sourceDefinitionId->value()) !== 1
            || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->rewardKey) !== 1
            || $this->units < 1
            || $this->units > 1_000_000
        ) {
            throw new InvalidArgumentException('Reward binding is invalid.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
