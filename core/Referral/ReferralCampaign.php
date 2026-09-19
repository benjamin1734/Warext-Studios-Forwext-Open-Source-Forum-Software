<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ReferralCampaign
{
    public DateTimeImmutable $startsAt;
    public ?DateTimeImmutable $endsAt;

    public function __construct(
        public EntityId $campaignId,
        public string $key,
        public string $name,
        public bool $active,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        public int $qualificationDelaySeconds,
        public int $attributionWindowSeconds,
        public int $duplicateNetworkLimit,
        public int $duplicateDeviceLimit,
        public ?int $maxQualifiedPerReferrer,
        public string $rewardKey,
        public int $rewardUnits,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->campaignId->value()) !== 1) {
            throw new InvalidArgumentException('Referral campaign id is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Referral campaign key is invalid.');
        }
        if (trim($this->name) === '' || strlen($this->name) > 120 || preg_match('//u', $this->name) !== 1) {
            throw new InvalidArgumentException('Referral campaign name is invalid.');
        }
        if ($this->qualificationDelaySeconds < 0 || $this->qualificationDelaySeconds > 31_536_000) {
            throw new InvalidArgumentException('Referral qualification delay is invalid.');
        }
        if ($this->attributionWindowSeconds < 3600 || $this->attributionWindowSeconds > 31_536_000) {
            throw new InvalidArgumentException('Referral attribution window is invalid.');
        }
        if ($this->duplicateNetworkLimit < 1 || $this->duplicateNetworkLimit > 100
            || $this->duplicateDeviceLimit < 1 || $this->duplicateDeviceLimit > 100
        ) {
            throw new InvalidArgumentException('Referral duplicate thresholds are invalid.');
        }
        if ($this->maxQualifiedPerReferrer !== null
            && ($this->maxQualifiedPerReferrer < 1 || $this->maxQualifiedPerReferrer > 1_000_000)
        ) {
            throw new InvalidArgumentException('Referral campaign qualification limit is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->rewardKey) !== 1
            || $this->rewardUnits < 1 || $this->rewardUnits > 1_000_000_000
        ) {
            throw new InvalidArgumentException('Referral reward definition is invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $this->startsAt = $startsAt->setTimezone($utc);
        $this->endsAt = $endsAt?->setTimezone($utc);
        if ($this->endsAt !== null && $this->endsAt <= $this->startsAt) {
            throw new InvalidArgumentException('Referral campaign end must be after its start.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function isOpen(DateTimeImmutable $at): bool
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        return $this->active
            && $at >= $this->startsAt
            && ($this->endsAt === null || $at < $this->endsAt);
    }
}
