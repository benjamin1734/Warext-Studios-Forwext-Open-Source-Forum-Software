<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Reward\RewardGrantGateway;
use Forwext\Core\Reward\RewardGrantRequest;
use InvalidArgumentException;
use Throwable;

final readonly class ReferralService implements ReferralRegistrationAttribution
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private ReferralRepository $referrals,
        private UserRepository $users,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
        private ?ReferralNotifier $notifier = null,
        private ?RewardGrantGateway $rewardGateway = null,
    ) {
    }

    /** @return list<ReferralCampaign> */
    public function activeCampaigns(EntityId $actor, DateTimeImmutable $at): array
    {
        $this->require($actor, 'referral.view_own');
        return array_values(array_filter(
            $this->referrals->campaigns(true),
            static fn (ReferralCampaign $campaign): bool => $campaign->isOpen($at),
        ));
    }

    /** @return list<ReferralCampaign> */
    public function campaigns(EntityId $actor): array
    {
        $this->require($actor, 'referral.manage');
        return $this->referrals->campaigns(false);
    }

    public function ensureLink(EntityId $actor, EntityId $campaignId, DateTimeImmutable $at): ReferralLink
    {
        $this->require($actor, 'referral.view_own');
        $this->require($actor, 'invite.create');
        $campaign = $this->referrals->campaign($campaignId)
            ?? throw new ReferralException('Referral campaign was not found.');
        if (!$campaign->isOpen($at)) {
            throw new ReferralException('Referral campaign is not currently open.');
        }

        $existing = $this->referrals->linkForOwner($campaignId, $actor);
        if ($existing !== null) {
            if (!$existing->isAvailable($at)) {
                throw new ReferralException('Referral link is no longer available.');
            }
            return $existing;
        }

        $expiresAt = $campaign->endsAt;
        $link = new ReferralLink(
            ReferralLink::generateId(),
            $campaignId,
            $actor,
            ReferralLink::generateCode(),
            $expiresAt,
            false,
            self::utc($at),
        );
        $this->referrals->saveLink($link, $at);
        return $link;
    }

    public function capture(string $code, DateTimeImmutable $at): ?ReferralCapture
    {
        $link = $this->referrals->linkByCode($code);
        if ($link === null || !$link->isAvailable($at)) {
            return null;
        }
        $campaign = $this->referrals->campaign($link->campaignId);
        if ($campaign === null || !$campaign->isOpen($at)) {
            return null;
        }

        $this->referrals->recordClick($link, $at);
        $ttl = $campaign->attributionWindowSeconds;
        $now = self::utc($at);
        foreach ([$campaign->endsAt, $link->expiresAt] as $end) {
            if ($end !== null) {
                $remaining = $end->getTimestamp() - $now->getTimestamp();
                $ttl = min($ttl, max(1, $remaining));
            }
        }
        return new ReferralCapture($link->code, $ttl);
    }

    public function attributeRegistration(
        EntityId $referredUserId,
        ?string $code,
        string $ipFingerprint,
        ?string $deviceFingerprint,
        DateTimeImmutable $at,
    ): void {
        if ($code === null || preg_match('/^[A-Za-z0-9_-]{24,64}$/D', $code) !== 1) {
            return;
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $ipFingerprint) !== 1
            || ($deviceFingerprint !== null && preg_match('/^[a-f0-9]{64}$/D', $deviceFingerprint) !== 1)
        ) {
            return;
        }

        $link = $this->referrals->linkByCode($code);
        if ($link === null || !$link->isAvailable($at)) {
            return;
        }
        $campaign = $this->referrals->campaign($link->campaignId);
        if ($campaign === null || !$campaign->isOpen($at)) {
            return;
        }
        if ($this->referrals->attributionByReferred($referredUserId) !== null) {
            return;
        }

        $state = ReferralState::Attributed;
        $risk = null;
        if ($link->ownerUserId->equals($referredUserId)) {
            $state = ReferralState::Rejected;
            $risk = 'self_referral';
        } elseif ($this->referrals->fingerprintCount(
            $campaign->campaignId,
            $link->ownerUserId,
            'ip',
            $ipFingerprint,
        ) >= $campaign->duplicateNetworkLimit) {
            $state = ReferralState::Review;
            $risk = 'duplicate_network';
        } elseif ($deviceFingerprint !== null && $this->referrals->fingerprintCount(
            $campaign->campaignId,
            $link->ownerUserId,
            'device',
            $deviceFingerprint,
        ) >= $campaign->duplicateDeviceLimit) {
            $state = ReferralState::Review;
            $risk = 'duplicate_device';
        }

        $eligibleAt = self::utc($at)->add(new DateInterval('PT' . $campaign->qualificationDelaySeconds . 'S'));
        $this->referrals->saveAttribution(new ReferralAttribution(
            ReferralAttribution::generateId(),
            $campaign->campaignId,
            $link->linkId,
            $link->ownerUserId,
            $referredUserId,
            $state,
            $risk,
            $ipFingerprint,
            $deviceFingerprint,
            $at,
            $eligibleAt,
        ));
    }

    public function saveCampaign(
        EntityId $actor,
        ReferralCampaign $campaign,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): void {
        $this->require($actor, 'referral.manage');
        $before = $this->referrals->campaign($campaign->campaignId);
        $requestId ??= AuditRequestId::generate();
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString('referral.campaign.save'),
            'referral.campaign',
            $campaign->campaignId->value(),
            null,
            'referral.campaign.save',
            $requestId,
            $before === null ? [] : self::campaignSnapshot($before),
            self::campaignSnapshot($campaign),
            $at,
        );
        $this->audit->mutate($event, fn (): mixed => $this->referrals->saveCampaign($campaign, $at));
    }

    public function qualifyDue(?EntityId $actor, int $limit, DateTimeImmutable $at): int
    {
        if ($actor !== null) {
            $this->require($actor, 'referral.manage');
        }
        $qualified = 0;
        foreach ($this->referrals->dueAttributions($at, $limit) as $attribution) {
            $user = $this->users->find($attribution->referredUserId);
            if ($user === null) {
                $this->moveToReview($attribution, 'referred_user_missing', $at);
                continue;
            }
            if (in_array($user->status(), [UserStatus::PendingEmailVerification, UserStatus::PendingApproval], true)) {
                continue;
            }
            if ($user->status() !== UserStatus::Active) {
                $this->rejectAutomatically($attribution, 'account_ineligible', $at);
                continue;
            }

            $campaign = $this->referrals->campaign($attribution->campaignId);
            if ($campaign === null || !$campaign->active) {
                $this->moveToReview($attribution, 'campaign_inactive', $at);
                continue;
            }
            if ($campaign->maxQualifiedPerReferrer !== null
                && $this->referrals->qualifiedCount($campaign->campaignId, $attribution->referrerUserId)
                    >= $campaign->maxQualifiedPerReferrer
            ) {
                $this->moveToReview($attribution, 'referrer_limit', $at);
                continue;
            }

            $this->grant($attribution, $campaign, $at);
            ++$qualified;
        }
        return $qualified;
    }

    public function review(
        EntityId $actor,
        EntityId $attributionId,
        bool $approve,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): void {
        $this->require($actor, 'referral.manage');
        $attribution = $this->referrals->attribution($attributionId)
            ?? throw new ReferralException('Referral attribution was not found.');
        if (!in_array($attribution->state, [ReferralState::Review, ReferralState::Attributed], true)) {
            throw new ReferralException('Referral attribution is no longer reviewable.');
        }

        $requestId ??= AuditRequestId::generate();
        if ($approve) {
            $user = $this->users->find($attribution->referredUserId);
            if ($user === null || $user->status() !== UserStatus::Active) {
                throw new ReferralException('Only an active referred account can be approved.');
            }
            $campaign = $this->referrals->campaign($attribution->campaignId)
                ?? throw new ReferralException('Referral campaign was not found.');
            $after = $this->qualifiedCopy($attribution, $at, true);
            $event = $this->reviewAudit($actor, $attribution, $after, 'referral.review.approve', $requestId, $at);
            $this->audit->mutate($event, function () use ($attribution, $campaign, $at): void {
                $this->grant($attribution, $campaign, $at, notify:false, markReviewed:true);
            });
            $this->safeNotify($after, $campaign);
            return;
        }

        $after = $this->rejectedCopy($attribution, 'staff_rejected', $at);
        $event = $this->reviewAudit($actor, $attribution, $after, 'referral.review.reject', $requestId, $at);
        $this->audit->mutate($event, fn (): mixed => $this->referrals->saveAttribution($after));
    }

    /** @return list<ReferralAttribution> */
    public function reviewQueue(EntityId $actor, int $limit = 100): array
    {
        $this->require($actor, 'referral.manage');
        return $this->referrals->reviewQueue($limit);
    }

    /** @return list<ReferralLink> */
    public function ownLinks(EntityId $actor): array
    {
        $this->require($actor, 'referral.view_own');
        return $this->referrals->linksForOwner($actor);
    }

    public function ownAnalytics(EntityId $actor): ReferralAnalytics
    {
        $this->require($actor, 'referral.view_own');
        return $this->referrals->analytics($actor);
    }

    /** @return list<ReferralReward> */
    public function ownRewards(EntityId $actor, int $limit = 100): array
    {
        $this->require($actor, 'referral.view_own');
        return $this->referrals->rewardsForUser($actor, $limit);
    }

    public function siteAnalytics(EntityId $actor): ReferralAnalytics
    {
        $this->require($actor, 'referral.manage');
        return $this->referrals->analytics();
    }

    public function canManage(EntityId $actor): bool
    {
        return $this->allows($actor, 'referral.manage');
    }

    private function grant(
        ReferralAttribution $attribution,
        ReferralCampaign $campaign,
        DateTimeImmutable $at,
        bool $notify = true,
        bool $markReviewed = false,
    ): void {
        $qualified = $this->qualifiedCopy($attribution, $at, $markReviewed);
        $this->database->transaction(function () use ($qualified, $campaign, $at): void {
            $this->referrals->saveAttribution($qualified);
            if ($this->referrals->rewardForAttribution($qualified->attributionId) === null) {
                $this->referrals->saveReward(new ReferralReward(
                    ReferralReward::generateId(),
                    $qualified->attributionId,
                    $campaign->campaignId,
                    $qualified->referrerUserId,
                    $campaign->rewardKey,
                    $campaign->rewardUnits,
                    ReferralRewardState::Granted,
                    $at,
                ));
            }
        });
        $this->safeRewardGrant($qualified, $campaign, $at);
        if ($notify) {
            $this->safeNotify($qualified, $campaign);
        }
    }

    private function safeRewardGrant(
        ReferralAttribution $attribution,
        ReferralCampaign $campaign,
        DateTimeImmutable $at,
    ): void {
        if ($this->rewardGateway === null) return;
        try {
            $this->rewardGateway->grant(new RewardGrantRequest(
                $attribution->referrerUserId,
                'referral',
                $attribution->attributionId->value(),
                $campaign->rewardKey,
                $campaign->rewardUnits,
            ), $at);
        } catch (Throwable) {
            // Referral qualification/local reward ledger is authoritative; common fulfillment retries independently.
        }
    }

    private function safeNotify(ReferralAttribution $attribution, ReferralCampaign $campaign): void
    {
        if ($this->notifier === null) {
            return;
        }
        try {
            $this->notifier->qualified($attribution, $campaign);
        } catch (Throwable) {
            // Durable qualification/reward state must not be rolled back by notification delivery.
        }
    }

    private function moveToReview(ReferralAttribution $attribution, string $risk, DateTimeImmutable $at): void
    {
        $this->referrals->saveAttribution(new ReferralAttribution(
            $attribution->attributionId,
            $attribution->campaignId,
            $attribution->linkId,
            $attribution->referrerUserId,
            $attribution->referredUserId,
            ReferralState::Review,
            $risk,
            $attribution->ipFingerprint,
            $attribution->deviceFingerprint,
            $attribution->attributedAt,
            $attribution->eligibleAt,
            null,
            self::utc($at),
        ));
    }

    private function rejectAutomatically(ReferralAttribution $attribution, string $risk, DateTimeImmutable $at): void
    {
        $this->referrals->saveAttribution($this->rejectedCopy($attribution, $risk, $at));
    }

    private function qualifiedCopy(
        ReferralAttribution $attribution,
        DateTimeImmutable $at,
        bool $markReviewed = false,
    ): ReferralAttribution
    {
        return new ReferralAttribution(
            $attribution->attributionId,
            $attribution->campaignId,
            $attribution->linkId,
            $attribution->referrerUserId,
            $attribution->referredUserId,
            ReferralState::Qualified,
            null,
            $attribution->ipFingerprint,
            $attribution->deviceFingerprint,
            $attribution->attributedAt,
            $attribution->eligibleAt,
            self::utc($at),
            $markReviewed ? self::utc($at) : $attribution->reviewedAt,
        );
    }

    private function rejectedCopy(ReferralAttribution $attribution, string $risk, DateTimeImmutable $at): ReferralAttribution
    {
        return new ReferralAttribution(
            $attribution->attributionId,
            $attribution->campaignId,
            $attribution->linkId,
            $attribution->referrerUserId,
            $attribution->referredUserId,
            ReferralState::Rejected,
            $risk,
            $attribution->ipFingerprint,
            $attribution->deviceFingerprint,
            $attribution->attributedAt,
            $attribution->eligibleAt,
            null,
            self::utc($at),
        );
    }

    private function reviewAudit(
        EntityId $actor,
        ReferralAttribution $before,
        ReferralAttribution $after,
        string $action,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): AuditEvent {
        return new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString($action),
            'referral.attribution',
            $before->attributionId->value(),
            null,
            $action,
            $requestId,
            ['state'=>$before->state->value,'risk'=>$before->riskCode],
            ['state'=>$after->state->value,'risk'=>$after->riskCode],
            $at,
        );
    }

    /** @return array<string,scalar|null> */
    private static function campaignSnapshot(ReferralCampaign $campaign): array
    {
        return [
            'key'=>$campaign->key,
            'active'=>$campaign->active,
            'starts_at'=>$campaign->startsAt->format(DATE_ATOM),
            'ends_at'=>$campaign->endsAt?->format(DATE_ATOM),
            'qualification_delay'=>$campaign->qualificationDelaySeconds,
            'attribution_window'=>$campaign->attributionWindowSeconds,
            'network_limit'=>$campaign->duplicateNetworkLimit,
            'device_limit'=>$campaign->duplicateDeviceLimit,
            'max_qualified'=>$campaign->maxQualifiedPerReferrer,
            'reward_key'=>$campaign->rewardKey,
            'reward_units'=>$campaign->rewardUnits,
        ];
    }

    private function require(EntityId $actor, string $permission): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    private function allows(EntityId $actor, string $permission): bool
    {
        return $this->authorizer->allows($actor, PermissionKey::fromString($permission));
    }

    private static function utc(DateTimeImmutable $at): DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
