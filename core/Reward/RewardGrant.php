<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class RewardGrant
{
    public DateTimeImmutable $createdAt;
    public ?DateTimeImmutable $appliedAt;
    public ?DateTimeImmutable $revokedAt;

    public function __construct(
        public EntityId $grantId,
        public EntityId $recipientUserId,
        public string $sourceType,
        public string $sourceId,
        public string $rewardKey,
        public int $units,
        public RewardGrantState $state,
        public ?EntityId $definitionId,
        public ?string $providerKey,
        public ?EntityId $targetId,
        public ?string $failureCode,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $appliedAt = null,
        ?DateTimeImmutable $revokedAt = null,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->grantId->value()) !== 1) {
            throw new InvalidArgumentException('Reward grant id is invalid.');
        }
        UserId::assert($this->recipientUserId);
        new RewardGrantRequest($this->recipientUserId, $this->sourceType, $this->sourceId, $this->rewardKey, $this->units);
        if ($this->definitionId !== null && preg_match('/^[a-f0-9]{32}$/D', $this->definitionId->value()) !== 1) {
            throw new InvalidArgumentException('Reward definition id snapshot is invalid.');
        }
        if (($this->providerKey === null) !== ($this->targetId === null)) {
            throw new InvalidArgumentException('Reward provider snapshot must be complete or absent.');
        }
        if ($this->providerKey !== null
            && (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->providerKey) !== 1
                || preg_match('/^[a-f0-9]{32}$/D', $this->targetId?->value() ?? '') !== 1)
        ) {
            throw new InvalidArgumentException('Reward provider snapshot is invalid.');
        }
        if ($this->failureCode !== null
            && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->failureCode) !== 1
        ) {
            throw new InvalidArgumentException('Reward failure code is invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->appliedAt = $appliedAt?->setTimezone($utc);
        $this->revokedAt = $revokedAt?->setTimezone($utc);
        if ($this->state === RewardGrantState::Applied && $this->appliedAt === null) {
            throw new InvalidArgumentException('Applied reward grant requires applied timestamp.');
        }
        if ($this->state === RewardGrantState::Revoked && $this->revokedAt === null) {
            throw new InvalidArgumentException('Revoked reward grant requires revoked timestamp.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
