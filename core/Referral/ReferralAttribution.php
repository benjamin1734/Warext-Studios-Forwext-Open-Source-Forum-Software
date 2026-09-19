<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ReferralAttribution
{
    public DateTimeImmutable $attributedAt;
    public DateTimeImmutable $eligibleAt;
    public ?DateTimeImmutable $qualifiedAt;
    public ?DateTimeImmutable $reviewedAt;

    public function __construct(
        public EntityId $attributionId,
        public EntityId $campaignId,
        public EntityId $linkId,
        public EntityId $referrerUserId,
        public EntityId $referredUserId,
        public ReferralState $state,
        public ?string $riskCode,
        public string $ipFingerprint,
        public ?string $deviceFingerprint,
        DateTimeImmutable $attributedAt,
        DateTimeImmutable $eligibleAt,
        ?DateTimeImmutable $qualifiedAt = null,
        ?DateTimeImmutable $reviewedAt = null,
    ) {
        UserId::assert($this->referrerUserId);
        UserId::assert($this->referredUserId);
        foreach ([$this->attributionId, $this->campaignId, $this->linkId] as $id) {
            if (preg_match('/^[a-f0-9]{32}$/D', $id->value()) !== 1) {
                throw new InvalidArgumentException('Referral attribution id is invalid.');
            }
        }
        if ($this->riskCode !== null && preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->riskCode) !== 1) {
            throw new InvalidArgumentException('Referral risk code is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->ipFingerprint) !== 1
            || ($this->deviceFingerprint !== null && preg_match('/^[a-f0-9]{64}$/D', $this->deviceFingerprint) !== 1)
        ) {
            throw new InvalidArgumentException('Referral fingerprint is invalid.');
        }
        $utc = new DateTimeZone('UTC');
        $this->attributedAt = $attributedAt->setTimezone($utc);
        $this->eligibleAt = $eligibleAt->setTimezone($utc);
        $this->qualifiedAt = $qualifiedAt?->setTimezone($utc);
        $this->reviewedAt = $reviewedAt?->setTimezone($utc);
        if ($this->eligibleAt < $this->attributedAt) {
            throw new InvalidArgumentException('Referral eligibility time is invalid.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
