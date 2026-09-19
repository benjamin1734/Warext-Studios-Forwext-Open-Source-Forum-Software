<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ReferralReward
{
    public DateTimeImmutable $grantedAt;
    public ?DateTimeImmutable $revokedAt;

    public function __construct(
        public EntityId $rewardId,
        public EntityId $attributionId,
        public EntityId $campaignId,
        public EntityId $recipientUserId,
        public string $rewardKey,
        public int $units,
        public ReferralRewardState $state,
        DateTimeImmutable $grantedAt,
        ?DateTimeImmutable $revokedAt = null,
    ) {
        UserId::assert($this->recipientUserId);
        if (preg_match('/^[a-f0-9]{32}$/D', $this->rewardId->value()) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $this->attributionId->value()) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $this->campaignId->value()) !== 1
            || preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->rewardKey) !== 1
            || $this->units < 1
        ) {
            throw new InvalidArgumentException('Referral reward is invalid.');
        }
        $utc = new DateTimeZone('UTC');
        $this->grantedAt = $grantedAt->setTimezone($utc);
        $this->revokedAt = $revokedAt?->setTimezone($utc);
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
