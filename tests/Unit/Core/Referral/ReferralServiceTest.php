<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Referral;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Referral\ReferralAnalytics;
use Forwext\Core\Referral\ReferralAttribution;
use Forwext\Core\Referral\ReferralCampaign;
use Forwext\Core\Referral\ReferralLink;
use Forwext\Core\Referral\ReferralRepository;
use Forwext\Core\Referral\ReferralReward;
use Forwext\Core\Referral\ReferralRewardState;
use Forwext\Core\Referral\ReferralService;
use Forwext\Core\Referral\ReferralState;
use PHPUnit\Framework\TestCase;

final class ReferralServiceTest extends TestCase
{
    public function testCreatingLinkRequiresInviteCreateInAdditionToOwnReferralView(): void
    {
        $actor = UserId::generate();
        $repo = new MemoryReferralRepository();
        $campaign = $this->campaign();
        $repo->saveCampaign($campaign, $this->now());
        $service = $this->service(
            $repo,
            new MemoryReferralUsers(),
            [$actor->value()=>['referral.view_own'=>true,'invite.create'=>false]],
        );

        $this->expectException(PermissionDeniedException::class);
        $service->ensureLink($actor, $campaign->campaignId, $this->now());
    }

    public function testDuplicateNetworkGoesToReviewAndSelfReferralIsRejected(): void
    {
        $referrer = UserId::generate();
        $first = UserId::generate();
        $second = UserId::generate();
        $repo = new MemoryReferralRepository();
        $campaign = $this->campaign(networkLimit:1, deviceLimit:2);
        $repo->saveCampaign($campaign, $this->now());
        $link = new ReferralLink(
            ReferralLink::generateId(),
            $campaign->campaignId,
            $referrer,
            ReferralLink::generateCode(),
            null,
            false,
            $this->now(),
        );
        $repo->saveLink($link, $this->now());

        $ip = str_repeat('a', 64);
        $device = str_repeat('b', 64);
        $repo->saveAttribution(new ReferralAttribution(
            ReferralAttribution::generateId(),
            $campaign->campaignId,
            $link->linkId,
            $referrer,
            $first,
            ReferralState::Attributed,
            null,
            $ip,
            $device,
            $this->now(),
            $this->now(),
        ));

        $service = $this->service($repo, new MemoryReferralUsers(), []);
        $service->attributeRegistration($second, $link->code, $ip, str_repeat('c', 64), $this->now());

        $secondAttribution = $repo->attributionByReferred($second);
        self::assertNotNull($secondAttribution);
        self::assertSame(ReferralState::Review, $secondAttribution->state);
        self::assertSame('duplicate_network', $secondAttribution->riskCode);

        $selfRepo = new MemoryReferralRepository();
        $selfRepo->saveCampaign($campaign, $this->now());
        $selfRepo->saveLink($link, $this->now());
        $selfService = $this->service($selfRepo, new MemoryReferralUsers(), []);
        $selfService->attributeRegistration(
            $referrer,
            $link->code,
            str_repeat('d', 64),
            str_repeat('e', 64),
            $this->now(),
        );

        self::assertSame(ReferralState::Rejected, $selfRepo->attributionByReferred($referrer)?->state);
        self::assertSame('self_referral', $selfRepo->attributionByReferred($referrer)?->riskCode);
    }

    public function testQualificationRequiresActiveAccountAndRewardIsIdempotent(): void
    {
        $referrer = UserId::generate();
        $referred = UserId::generate();
        $repo = new MemoryReferralRepository();
        $campaign = $this->campaign(delay:0);
        $repo->saveCampaign($campaign, $this->now());
        $link = new ReferralLink(
            ReferralLink::generateId(),
            $campaign->campaignId,
            $referrer,
            ReferralLink::generateCode(),
            null,
            false,
            $this->now(),
        );
        $repo->saveLink($link, $this->now());
        $attribution = new ReferralAttribution(
            ReferralAttribution::generateId(),
            $campaign->campaignId,
            $link->linkId,
            $referrer,
            $referred,
            ReferralState::Attributed,
            null,
            str_repeat('1', 64),
            str_repeat('2', 64),
            $this->now(),
            $this->now(),
        );
        $repo->saveAttribution($attribution);

        $users = new MemoryReferralUsers();
        $users->add($this->user($referred, UserStatus::Active));
        $service = $this->service($repo, $users, []);

        self::assertSame(1, $service->qualifyDue(null, 100, $this->now()));
        self::assertSame(ReferralState::Qualified, $repo->attribution($attribution->attributionId)?->state);
        $reward = $repo->rewardForAttribution($attribution->attributionId);
        self::assertNotNull($reward);
        self::assertSame(ReferralRewardState::Granted, $reward->state);
        self::assertSame($campaign->rewardUnits, $reward->units);

        self::assertSame(0, $service->qualifyDue(null, 100, $this->now()));
        self::assertCount(1, $repo->rewards);
    }

    public function testPendingAccountWaitsAndStaffCampaignMutationIsAudited(): void
    {
        $manager = UserId::generate();
        $referrer = UserId::generate();
        $referred = UserId::generate();
        $repo = new MemoryReferralRepository();
        $campaign = $this->campaign(delay:0);
        $repo->saveCampaign($campaign, $this->now());
        $link = new ReferralLink(
            ReferralLink::generateId(),
            $campaign->campaignId,
            $referrer,
            ReferralLink::generateCode(),
            null,
            false,
            $this->now(),
        );
        $repo->saveLink($link, $this->now());
        $attribution = new ReferralAttribution(
            ReferralAttribution::generateId(),
            $campaign->campaignId,
            $link->linkId,
            $referrer,
            $referred,
            ReferralState::Attributed,
            null,
            str_repeat('3', 64),
            null,
            $this->now(),
            $this->now(),
        );
        $repo->saveAttribution($attribution);

        $users = new MemoryReferralUsers();
        $users->add($this->user($referred, UserStatus::PendingEmailVerification));
        $audit = new MemoryReferralAudit();
        $service = $this->service(
            $repo,
            $users,
            [$manager->value()=>['referral.manage'=>true]],
            $audit,
        );

        self::assertSame(0, $service->qualifyDue(null, 100, $this->now()));
        self::assertSame(ReferralState::Attributed, $repo->attribution($attribution->attributionId)?->state);

        $changed = new ReferralCampaign(
            $campaign->campaignId,
            $campaign->key,
            'Updated Campaign',
            true,
            $campaign->startsAt,
            null,
            0,
            $campaign->attributionWindowSeconds,
            $campaign->duplicateNetworkLimit,
            $campaign->duplicateDeviceLimit,
            null,
            $campaign->rewardKey,
            2,
        );
        $service->saveCampaign($manager, $changed, $this->now());

        self::assertCount(1, $audit->events);
        self::assertSame('referral.campaign.save', $audit->events[0]->action->value());
        self::assertSame('Updated Campaign', $repo->campaign($campaign->campaignId)?->name);
    }

    private function service(
        MemoryReferralRepository $repo,
        MemoryReferralUsers $users,
        array $permissions,
        ?MemoryReferralAudit $audit = null,
    ): ReferralService {
        $database = new MemoryReferralDatabase();
        return new ReferralService(
            $database,
            $repo,
            $users,
            new PermissionAuthorizer(
                new PermissionEngine(new MemoryReferralPermissionRules($permissions)),
                new MemoryReferralAssignments(array_keys($permissions)),
            ),
            $audit ?? new MemoryReferralAudit(),
        );
    }

    private function campaign(
        int $networkLimit = 1,
        int $deviceLimit = 1,
        int $delay = 0,
    ): ReferralCampaign {
        return new ReferralCampaign(
            ReferralCampaign::generateId(),
            'community',
            'Community',
            true,
            $this->now()->modify('-1 hour'),
            null,
            $delay,
            2_592_000,
            $networkLimit,
            $deviceLimit,
            null,
            'referral.credit',
            5,
        );
    }

    private function user(EntityId $id, UserStatus $status): User
    {
        return User::create(
            $id,
            Username::fromString('u_' . substr($id->value(), 0, 12)),
            EmailAddress::fromString(substr($id->value(), 0, 12) . '@example.com'),
            $status,
            UserLocale::fromString('en-US'),
            UserTimezone::fromString('UTC'),
            $this->now()->modify('-1 day'),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-19 12:00:00', new DateTimeZone('UTC'));
    }
}

final class MemoryReferralRepository implements ReferralRepository
{
    /** @var array<string,ReferralCampaign> */
    public array $campaignsMap = [];
    /** @var array<string,ReferralLink> */
    public array $links = [];
    /** @var array<string,ReferralAttribution> */
    public array $attributions = [];
    /** @var array<string,ReferralReward> */
    public array $rewards = [];
    public int $clicks = 0;

    public function campaigns(bool $activeOnly = false): array
    {
        return array_values(array_filter(
            $this->campaignsMap,
            static fn (ReferralCampaign $campaign): bool => !$activeOnly || $campaign->active,
        ));
    }

    public function campaign(EntityId $campaignId): ?ReferralCampaign
    {
        return $this->campaignsMap[$campaignId->value()] ?? null;
    }

    public function saveCampaign(ReferralCampaign $campaign, DateTimeImmutable $at): void
    {
        $this->campaignsMap[$campaign->campaignId->value()] = $campaign;
    }

    public function linkForOwner(EntityId $campaignId, EntityId $ownerUserId): ?ReferralLink
    {
        foreach ($this->links as $link) {
            if ($link->campaignId->equals($campaignId) && $link->ownerUserId->equals($ownerUserId)) {
                return $link;
            }
        }
        return null;
    }

    public function linkByCode(string $code): ?ReferralLink
    {
        foreach ($this->links as $link) {
            if (hash_equals($link->code, $code)) return $link;
        }
        return null;
    }

    public function linksForOwner(EntityId $ownerUserId): array
    {
        return array_values(array_filter(
            $this->links,
            static fn (ReferralLink $link): bool => $link->ownerUserId->equals($ownerUserId),
        ));
    }

    public function saveLink(ReferralLink $link, DateTimeImmutable $at): void
    {
        $this->links[$link->linkId->value()] = $link;
    }

    public function recordClick(ReferralLink $link, DateTimeImmutable $at): void
    {
        ++$this->clicks;
    }

    public function attributionByReferred(EntityId $referredUserId): ?ReferralAttribution
    {
        foreach ($this->attributions as $attribution) {
            if ($attribution->referredUserId->equals($referredUserId)) return $attribution;
        }
        return null;
    }

    public function attribution(EntityId $attributionId): ?ReferralAttribution
    {
        return $this->attributions[$attributionId->value()] ?? null;
    }

    public function saveAttribution(ReferralAttribution $attribution): void
    {
        $this->attributions[$attribution->attributionId->value()] = $attribution;
    }

    public function fingerprintCount(
        EntityId $campaignId,
        EntityId $referrerUserId,
        string $kind,
        string $fingerprint,
    ): int {
        $count = 0;
        foreach ($this->attributions as $attribution) {
            if (!$attribution->campaignId->equals($campaignId)
                || !$attribution->referrerUserId->equals($referrerUserId)
                || $attribution->state === ReferralState::Rejected
            ) continue;
            $value = $kind === 'ip' ? $attribution->ipFingerprint : $attribution->deviceFingerprint;
            if ($value !== null && hash_equals($value, $fingerprint)) ++$count;
        }
        return $count;
    }

    public function dueAttributions(DateTimeImmutable $at, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->attributions,
            static fn (ReferralAttribution $item): bool =>
                $item->state === ReferralState::Attributed && $item->eligibleAt <= $at,
        )), 0, $limit);
    }

    public function reviewQueue(int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->attributions,
            static fn (ReferralAttribution $item): bool => $item->state === ReferralState::Review,
        )), 0, $limit);
    }

    public function qualifiedCount(EntityId $campaignId, EntityId $referrerUserId): int
    {
        return count(array_filter(
            $this->attributions,
            static fn (ReferralAttribution $item): bool =>
                $item->campaignId->equals($campaignId)
                && $item->referrerUserId->equals($referrerUserId)
                && $item->state === ReferralState::Qualified,
        ));
    }

    public function rewardForAttribution(EntityId $attributionId): ?ReferralReward
    {
        foreach ($this->rewards as $reward) {
            if ($reward->attributionId->equals($attributionId)) return $reward;
        }
        return null;
    }

    public function saveReward(ReferralReward $reward): void
    {
        $this->rewards[$reward->rewardId->value()] = $reward;
    }

    public function rewardsForUser(EntityId $userId, int $limit = 100): array
    {
        return array_slice(array_values(array_filter(
            $this->rewards,
            static fn (ReferralReward $reward): bool => $reward->recipientUserId->equals($userId),
        )), 0, $limit);
    }

    public function analytics(?EntityId $ownerUserId = null, ?EntityId $campaignId = null): ReferralAnalytics
    {
        $items = array_values(array_filter(
            $this->attributions,
            static fn (ReferralAttribution $item): bool =>
                ($ownerUserId === null || $item->referrerUserId->equals($ownerUserId))
                && ($campaignId === null || $item->campaignId->equals($campaignId)),
        ));
        $stateCount = static fn (ReferralState $state): int => count(array_filter(
            $items,
            static fn (ReferralAttribution $item): bool => $item->state === $state,
        ));
        $rewardUnits = 0;
        foreach ($this->rewards as $reward) {
            if (($ownerUserId === null || $reward->recipientUserId->equals($ownerUserId))
                && ($campaignId === null || $reward->campaignId->equals($campaignId))
                && $reward->state === ReferralRewardState::Granted
            ) {
                $rewardUnits += $reward->units;
            }
        }
        return new ReferralAnalytics(
            $this->clicks,
            count($items),
            $stateCount(ReferralState::Review),
            $stateCount(ReferralState::Qualified),
            $stateCount(ReferralState::Rejected),
            $rewardUnits,
        );
    }
}

final class MemoryReferralUsers implements UserRepository
{
    /** @var array<string,User> */
    private array $users = [];

    public function add(User $user): void
    {
        $this->users[$user->id()->value()] = $user;
    }

    public function find(EntityId $id): ?User { return $this->users[$id->value()] ?? null; }
    public function findByUsername(Username $username): ?User { return null; }
    public function findByEmail(EmailAddress $email): ?User { return null; }
    public function save(User $user): void { $this->users[$user->id()->value()] = $user; }
    public function history(EntityId $id, int $limit = 100, int $offset = 0): array { return []; }
}

final readonly class MemoryReferralPermissionRules implements PermissionRuleRepository
{
    /** @param array<string,array<string,bool>> $permissions */
    public function __construct(private array $permissions) {}

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        $allowed = $this->permissions[$assignment->userId()->value()][$key->value()] ?? false;
        return [new PermissionRule(
            PermissionSubjectType::User,
            $assignment->userId(),
            $allowed ? PermissionEffect::Allow : PermissionEffect::Deny,
        )];
    }
}

final readonly class MemoryReferralAssignments implements UserAccessAssignmentProvider
{
    /** @var array<string,true> */
    private array $ids;

    /** @param list<string> $ids */
    public function __construct(array $ids)
    {
        $this->ids = array_fill_keys($ids, true);
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        if (!isset($this->ids[$userId->value()])) return null;
        return new UserAccessAssignment(
            $userId,
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
        );
    }
}

final class MemoryReferralAudit implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    public function mutate(AuditEvent $event, callable $mutation): mixed
    {
        $result = $mutation();
        $this->events[] = $event;
        return $result;
    }
}

final class MemoryReferralDatabase implements TransactionalQueryExecutor
{
    private int $depth = 0;
    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->depth > 0; }
    public function transaction(\Closure $callback): mixed
    {
        ++$this->depth;
        try { return $callback($this); } finally { --$this->depth; }
    }
}
