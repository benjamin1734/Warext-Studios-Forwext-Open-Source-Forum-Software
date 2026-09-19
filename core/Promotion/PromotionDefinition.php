<?php

declare(strict_types=1);

namespace Forwext\Core\Promotion;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class PromotionDefinition
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $promotionId,
        public string $key,
        public string $name,
        public bool $active,
        public int $priority,
        public PromotionRuleType $ruleType,
        public int $threshold,
        public string $rewardKey,
        public int $units,
        public bool $revokeWhenUnqualified,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->promotionId->value()) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D', $this->key) !== 1
            || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->rewardKey) !== 1
            || trim($this->name) === ''
            || strlen($this->name) > 120
            || preg_match('//u', $this->name) !== 1
            || $this->priority < 0
            || $this->priority > 65535
            || $this->threshold < 1
            || $this->threshold > 1_000_000_000
            || $this->units < 1
            || $this->units > 1_000_000
        ) {
            throw new InvalidArgumentException('Promotion definition is invalid.');
        }
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);
        $this->updatedAt=$updatedAt->setTimezone($utc);
        if($this->updatedAt<$this->createdAt){
            throw new InvalidArgumentException('Promotion definition timestamps are invalid.');
        }
    }

    public static function generateId():EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
