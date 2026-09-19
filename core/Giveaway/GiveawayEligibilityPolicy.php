<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class GiveawayEligibilityPolicy
{
    /** @var list<EntityId> */
    public array $allowedRoleIds;

    /** @param list<EntityId> $allowedRoleIds */
    public function __construct(
        public EntityId $giveawayId,
        public int $minAccountAgeDays,
        public int $minPostCount,
        public bool $requireVerifiedAccount,
        array $allowedRoleIds,
        public GiveawayReferralRequirement $referralRequirement,
        public int $minQualifiedReferrals,
        public int $duplicateNetworkLimit,
        public int $duplicateDeviceLimit,
    ) {
        if ($this->minAccountAgeDays < 0 || $this->minAccountAgeDays > 36_500) {
            throw new InvalidArgumentException('Giveaway minimum account age is invalid.');
        }
        if ($this->minPostCount < 0 || $this->minPostCount > 100_000_000) {
            throw new InvalidArgumentException('Giveaway minimum post count is invalid.');
        }
        if ($this->minQualifiedReferrals < 0 || $this->minQualifiedReferrals > 1_000_000) {
            throw new InvalidArgumentException('Giveaway qualified referral threshold is invalid.');
        }
        if ($this->referralRequirement === GiveawayReferralRequirement::QualifiedReferrer
            && $this->minQualifiedReferrals < 1
        ) {
            throw new InvalidArgumentException('Qualified-referrer eligibility requires a positive referral threshold.');
        }
        if ($this->referralRequirement !== GiveawayReferralRequirement::QualifiedReferrer
            && $this->minQualifiedReferrals !== 0
        ) {
            throw new InvalidArgumentException('Referral threshold is only valid for qualified-referrer eligibility.');
        }
        if ($this->duplicateNetworkLimit < 0 || $this->duplicateNetworkLimit > 1000
            || $this->duplicateDeviceLimit < 0 || $this->duplicateDeviceLimit > 1000
        ) {
            throw new InvalidArgumentException('Giveaway duplicate fingerprint limit is invalid.');
        }

        $roles = [];
        foreach ($allowedRoleIds as $roleId) {
            if (!$roleId instanceof EntityId || preg_match('/^[a-f0-9]{32}$/D', $roleId->value()) !== 1) {
                throw new InvalidArgumentException('Giveaway eligibility role id is invalid.');
            }
            $roles[$roleId->value()] = $roleId;
        }
        if (count($roles) > 100) {
            throw new InvalidArgumentException('Giveaway eligibility role limit exceeded.');
        }
        ksort($roles, SORT_STRING);
        $this->allowedRoleIds = array_values($roles);
    }

    public static function defaults(EntityId $giveawayId): self
    {
        return new self(
            $giveawayId,
            0,
            0,
            true,
            [],
            GiveawayReferralRequirement::None,
            0,
            3,
            1,
        );
    }
}
