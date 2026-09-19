<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

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
use Forwext\Core\Domain\User\UserStatus;

final readonly class GiveawayParticipationService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private GiveawayRepository $giveaways,
        private GiveawayParticipationRepository $participation,
        private GiveawayEligibilityContextProvider $contexts,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ) {
    }

    public function policy(EntityId $actor, EntityId $giveawayId): GiveawayEligibilityPolicy
    {
        $giveaway = $this->giveaways->find($giveawayId)
            ?? throw new GiveawayException('Giveaway was not found.');
        $this->requireManager($actor, $giveaway);
        return $this->participation->policy($giveawayId)
            ?? GiveawayEligibilityPolicy::defaults($giveawayId);
    }

    public function savePolicy(
        EntityId $actor,
        GiveawayEligibilityPolicy $policy,
        DateTimeImmutable $at,
        ?AuditRequestId $requestId = null,
    ): void {
        $giveaway = $this->giveaways->find($policy->giveawayId)
            ?? throw new GiveawayException('Giveaway was not found.');
        $this->requireManager($actor, $giveaway);
        if (!in_array($giveaway->state, [GiveawayState::Draft, GiveawayState::Scheduled], true)
            || ($giveaway->state === GiveawayState::Scheduled
                && $giveaway->expectedPublishedState($at) !== GiveawayState::Scheduled)
        ) {
            throw new GiveawayParticipationException('Eligibility policy is locked after a giveaway opens.');
        }

        $before = $this->participation->policy($policy->giveawayId)
            ?? GiveawayEligibilityPolicy::defaults($policy->giveawayId);
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString('giveaway.eligibility.update'),
            'giveaway.eligibility',
            $policy->giveawayId->value(),
            null,
            'giveaway.eligibility.update',
            $requestId ?? AuditRequestId::generate(),
            self::policySnapshot($before),
            self::policySnapshot($policy),
            self::utc($at),
        );
        $this->audit->mutate($event, function () use ($policy, $at): void {
            $this->participation->savePolicy($policy, $at);
        });
    }

    public function canEnter(EntityId $actor): bool
    {
        return $this->authorizer->allows($actor, PermissionKey::fromString('giveaway.view'))
            && $this->authorizer->allows($actor, PermissionKey::fromString('giveaway.enter'));
    }

    public function requirements(EntityId $actor, EntityId $giveawayId): GiveawayEligibilityPolicy
    {
        $this->require($actor, 'giveaway.view');
        if ($this->giveaways->find($giveawayId) === null) {
            throw new GiveawayException('Giveaway was not found.');
        }
        return $this->participation->policy($giveawayId)
            ?? GiveawayEligibilityPolicy::defaults($giveawayId);
    }

    /** @return list<GiveawayEligibilityRoleOption> */
    public function roleOptions(EntityId $actor, EntityId $giveawayId): array
    {
        $giveaway = $this->giveaways->find($giveawayId)
            ?? throw new GiveawayException('Giveaway was not found.');
        $this->requireManager($actor, $giveaway);
        return $this->participation->availableRoles();
    }

    public function entry(EntityId $actor, EntityId $giveawayId): ?GiveawayEntry
    {
        $this->require($actor, 'giveaway.view');
        return $this->participation->entryForUser($giveawayId, $actor);
    }

    public function decision(
        EntityId $actor,
        EntityId $giveawayId,
        string $networkFingerprint,
        ?string $deviceFingerprint,
        DateTimeImmutable $at,
    ): GiveawayEligibilityDecision {
        $this->require($actor, 'giveaway.view');
        $this->require($actor, 'giveaway.enter');
        $giveaway = $this->giveaways->find($giveawayId)
            ?? throw new GiveawayException('Giveaway was not found.');
        return $this->evaluate($actor, $giveaway, $networkFingerprint, $deviceFingerprint, $at);
    }

    public function enter(
        EntityId $actor,
        EntityId $giveawayId,
        string $networkFingerprint,
        ?string $deviceFingerprint,
        DateTimeImmutable $at,
    ): GiveawayEntry {
        $this->require($actor, 'giveaway.view');
        $this->require($actor, 'giveaway.enter');
        self::assertFingerprint($networkFingerprint);
        if ($deviceFingerprint !== null) {
            self::assertFingerprint($deviceFingerprint);
        }

        return $this->database->transaction(function () use (
            $actor, $giveawayId, $networkFingerprint, $deviceFingerprint, $at,
        ): GiveawayEntry {
            if (!$this->participation->lockGiveaway($giveawayId)) {
                throw new GiveawayException('Giveaway was not found.');
            }
            $existing = $this->participation->entryForUser($giveawayId, $actor);
            if ($existing !== null) {
                return $existing;
            }

            $giveaway = $this->giveaways->find($giveawayId)
                ?? throw new GiveawayException('Giveaway was not found.');
            if ($giveaway->state !== GiveawayState::Open) {
                throw new GiveawayParticipationException('Giveaway is not open for participation.', ['not_open']);
            }

            $decision = $this->evaluate($actor, $giveaway, $networkFingerprint, $deviceFingerprint, $at);
            if (!$decision->eligible) {
                throw new GiveawayParticipationException(
                    'Giveaway eligibility requirements were not met.',
                    $decision->reasons,
                );
            }

            if ($giveaway->maxParticipants !== null
                && $this->participation->participantCount($giveawayId) >= $giveaway->maxParticipants
            ) {
                throw new GiveawayParticipationException(
                    'Giveaway participant capacity has been reached.',
                    ['capacity_reached'],
                );
            }

            $entry = new GiveawayEntry(
                GiveawayEntry::generateId(),
                $giveawayId,
                $actor,
                $giveaway->entriesPerUser,
                $networkFingerprint,
                $deviceFingerprint,
                self::utc($at),
            );
            $this->participation->saveEntry($entry);
            return $entry;
        });
    }

    private function evaluate(
        EntityId $actor,
        Giveaway $giveaway,
        string $networkFingerprint,
        ?string $deviceFingerprint,
        DateTimeImmutable $at,
    ): GiveawayEligibilityDecision {
        self::assertFingerprint($networkFingerprint);
        if ($deviceFingerprint !== null) {
            self::assertFingerprint($deviceFingerprint);
        }

        $reasons = [];
        if ($giveaway->ownerUserId->equals($actor)) {
            $reasons[] = 'self_entry';
        }
        if ($giveaway->state !== GiveawayState::Open) {
            $reasons[] = 'not_open';
        }

        $policy = $this->participation->policy($giveaway->giveawayId)
            ?? GiveawayEligibilityPolicy::defaults($giveaway->giveawayId);
        $context = $this->contexts->context($actor);

        if (in_array($context->status, [
            UserStatus::Suspended,
            UserStatus::Banned,
            UserStatus::Deactivated,
            UserStatus::DeletionPending,
        ], true)) {
            $reasons[] = 'account_restricted';
        }
        if ($policy->requireVerifiedAccount && $context->status !== UserStatus::Active) {
            $reasons[] = 'account_not_verified';
        }

        $at = self::utc($at);
        $ageSeconds = max(0, $at->getTimestamp() - $context->createdAt->getTimestamp());
        if (intdiv($ageSeconds, 86400) < $policy->minAccountAgeDays) {
            $reasons[] = 'account_age';
        }
        if ($context->visiblePostCount < $policy->minPostCount) {
            $reasons[] = 'post_count';
        }

        if ($policy->allowedRoleIds !== []) {
            $userRoles = array_fill_keys(
                array_map(static fn (EntityId $role): string => $role->value(), $context->roleIds),
                true,
            );
            $matches = false;
            foreach ($policy->allowedRoleIds as $roleId) {
                if (isset($userRoles[$roleId->value()])) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                $reasons[] = 'role';
            }
        }

        if ($policy->referralRequirement === GiveawayReferralRequirement::ReferredQualified
            && !$context->referredQualified
        ) {
            $reasons[] = 'referral';
        }
        if ($policy->referralRequirement === GiveawayReferralRequirement::QualifiedReferrer
            && $context->qualifiedReferralCount < $policy->minQualifiedReferrals
        ) {
            $reasons[] = 'referral';
        }

        if ($policy->duplicateNetworkLimit > 0
            && $this->participation->fingerprintParticipantCount(
                $giveaway->giveawayId,
                'network',
                $networkFingerprint,
            ) >= $policy->duplicateNetworkLimit
        ) {
            $reasons[] = 'duplicate_network';
        }
        if ($deviceFingerprint !== null
            && $policy->duplicateDeviceLimit > 0
            && $this->participation->fingerprintParticipantCount(
                $giveaway->giveawayId,
                'device',
                $deviceFingerprint,
            ) >= $policy->duplicateDeviceLimit
        ) {
            $reasons[] = 'duplicate_device';
        }

        return $reasons === []
            ? GiveawayEligibilityDecision::allow()
            : GiveawayEligibilityDecision::deny($reasons);
    }

    private function requireManager(EntityId $actor, Giveaway $giveaway): void
    {
        if ($this->authorizer->allows($actor, PermissionKey::fromString('giveaway.manage'))) {
            return;
        }
        if ($giveaway->ownerUserId->equals($actor)
            && $this->authorizer->allows($actor, PermissionKey::fromString('giveaway.create'))
        ) {
            return;
        }
        $this->require($actor, 'giveaway.manage');
    }

    private function require(EntityId $actor, string $permission): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    /** @return array<string,scalar|null> */
    private static function policySnapshot(GiveawayEligibilityPolicy $policy): array
    {
        return [
            'min_account_age_days'=>$policy->minAccountAgeDays,
            'min_post_count'=>$policy->minPostCount,
            'require_verified_account'=>$policy->requireVerifiedAccount,
            'allowed_role_count'=>count($policy->allowedRoleIds),
            'referral_requirement'=>$policy->referralRequirement->value,
            'min_qualified_referrals'=>$policy->minQualifiedReferrals,
            'duplicate_network_limit'=>$policy->duplicateNetworkLimit,
            'duplicate_device_limit'=>$policy->duplicateDeviceLimit,
        ];
    }

    private static function assertFingerprint(string $fingerprint): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new GiveawayParticipationException('Giveaway participation fingerprint is invalid.');
        }
    }

    private static function utc(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone('UTC'));
    }
}
