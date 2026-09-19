<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ReferralRepository
{
    /** @return list<ReferralCampaign> */
    public function campaigns(bool $activeOnly = false): array;

    public function campaign(EntityId $campaignId): ?ReferralCampaign;

    public function saveCampaign(ReferralCampaign $campaign, DateTimeImmutable $at): void;

    public function linkForOwner(EntityId $campaignId, EntityId $ownerUserId): ?ReferralLink;

    public function linkByCode(string $code): ?ReferralLink;

    /** @return list<ReferralLink> */
    public function linksForOwner(EntityId $ownerUserId): array;

    public function saveLink(ReferralLink $link, DateTimeImmutable $at): void;

    public function recordClick(ReferralLink $link, DateTimeImmutable $at): void;

    public function attributionByReferred(EntityId $referredUserId): ?ReferralAttribution;

    public function attribution(EntityId $attributionId): ?ReferralAttribution;

    public function saveAttribution(ReferralAttribution $attribution): void;

    public function fingerprintCount(
        EntityId $campaignId,
        EntityId $referrerUserId,
        string $kind,
        string $fingerprint,
    ): int;

    /** @return list<ReferralAttribution> */
    public function dueAttributions(DateTimeImmutable $at, int $limit): array;

    /** @return list<ReferralAttribution> */
    public function reviewQueue(int $limit): array;

    public function qualifiedCount(EntityId $campaignId, EntityId $referrerUserId): int;

    public function rewardForAttribution(EntityId $attributionId): ?ReferralReward;

    public function saveReward(ReferralReward $reward): void;

    /** @return list<ReferralReward> */
    public function rewardsForUser(EntityId $userId, int $limit = 100): array;

    public function analytics(?EntityId $ownerUserId = null, ?EntityId $campaignId = null): ReferralAnalytics;
}
