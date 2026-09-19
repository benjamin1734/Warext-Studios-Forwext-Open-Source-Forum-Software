<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class RewardDefinition
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $rewardId,
        public string $key,
        public string $name,
        public bool $active,
        public string $providerKey,
        public EntityId $targetId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->rewardId->value()) !== 1
            || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->key) !== 1
            || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->providerKey) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $this->targetId->value()) !== 1
            || trim($this->name) === ''
            || strlen($this->name) > 120
            || preg_match('//u', $this->name) !== 1
        ) {
            throw new InvalidArgumentException('Reward definition is invalid.');
        }
        $utc = new DateTimeZone('UTC');
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);
        if ($this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Reward definition timestamps are invalid.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
