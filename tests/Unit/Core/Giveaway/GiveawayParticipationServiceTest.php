<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Giveaway;

use Closure;
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
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Giveaway\Giveaway;
use Forwext\Core\Giveaway\GiveawayEligibilityContext;
use Forwext\Core\Giveaway\GiveawayEligibilityContextProvider;
use Forwext\Core\Giveaway\GiveawayEligibilityPolicy;
use Forwext\Core\Giveaway\GiveawayEligibilityRoleOption;
use Forwext\Core\Giveaway\GiveawayEntry;
use Forwext\Core\Giveaway\GiveawayParticipationException;
use Forwext\Core\Giveaway\GiveawayParticipationRepository;
use Forwext\Core\Giveaway\GiveawayParticipationService;
use Forwext\Core\Giveaway\GiveawayPrize;
use Forwext\Core\Giveaway\GiveawayReferralRequirement;
use Forwext\Core\Giveaway\GiveawayRepository;
use Forwext\Core\Giveaway\GiveawayState;
use PHPUnit\Framework\TestCase;

final class GiveawayParticipationServiceTest extends TestCase
{
    public function testEligibleEntryIsIdempotentAndUsesConfiguredEntryCount(): void
    {
        $owner = UserId::generate();
        $actor = UserId::generate();
        $role = EntityId::fromString('11111111111111111111111111111111');
        $giveaway = $this->giveaway($owner, 2, 10);
        $giveaways = new ParticipationGiveawayRepository([$giveaway]);
        $participation = new MemoryParticipationRepository();
        $participation->savePolicy(new GiveawayEligibilityPolicy(
            $giveaway->giveawayId,
            30,
            10,
            true,
            [$role],
            GiveawayReferralRequirement::ReferredQualified,
            0,
            3,
            1,
        ), $this->at('2026-09-19 12:00:00'));
        $contexts = new MemoryEligibilityContexts([
            $actor->value()=>new GiveawayEligibilityContext(
                $actor,
                UserStatus::Active,
                $this->at('2026-06-01 12:00:00'),
                25,
                [$role],
                true,
                0,
            ),
        ]);
        $service = $this->service($giveaways, $participation, $contexts, [
            $actor->value()=>['giveaway.view'=>true,'giveaway.enter'=>true],
        ]);

        $first = $service->enter($actor, $giveaway->giveawayId, str_repeat('a', 64), str_repeat('b', 64), $this->at('2026-09-19 12:30:00'));
        $second = $service->enter($actor, $giveaway->giveawayId, str_repeat('a', 64), str_repeat('b', 64), $this->at('2026-09-19 12:31:00'));

        self::assertSame($first->entryId->value(), $second->entryId->value());
        self::assertSame(2, $first->entryCount);
        self::assertSame(1, $participation->participantCount($giveaway->giveawayId));
    }

    public function testDecisionReportsAgePostVerificationRoleAndReferralFailures(): void
    {
        $owner = UserId::generate();
        $actor = UserId::generate();
        $requiredRole = EntityId::fromString('22222222222222222222222222222222');
        $giveaway = $this->giveaway($owner, 1, 10);
        $giveaways = new ParticipationGiveawayRepository([$giveaway]);
        $participation = new MemoryParticipationRepository();
        $participation->savePolicy(new GiveawayEligibilityPolicy(
            $giveaway->giveawayId,
            60,
            5,
            true,
            [$requiredRole],
            GiveawayReferralRequirement::QualifiedReferrer,
            2,
            0,
            0,
        ), $this->at('2026-09-19 12:00:00'));
        $contexts = new MemoryEligibilityContexts([
            $actor->value()=>new GiveawayEligibilityContext(
                $actor,
                UserStatus::PendingApproval,
                $this->at('2026-09-10 12:00:00'),
                1,
                [],
                false,
                0,
            ),
        ]);
        $service = $this->service($giveaways, $participation, $contexts, [
            $actor->value()=>['giveaway.view'=>true,'giveaway.enter'=>true],
        ]);

        $decision = $service->decision(
            $actor,
            $giveaway->giveawayId,
            str_repeat('c', 64),
            null,
            $this->at('2026-09-19 12:30:00'),
        );

        self::assertFalse($decision->eligible);
        self::assertEqualsCanonicalizing(
            ['account_not_verified','account_age','post_count','role','referral'],
            $decision->reasons,
        );
    }

    public function testOwnerCannotEnterOwnGiveaway(): void
    {
        $owner = UserId::generate();
        $giveaway = $this->giveaway($owner, 1, 10);
        $contexts = new MemoryEligibilityContexts([
            $owner->value()=>new GiveawayEligibilityContext(
                $owner,
                UserStatus::Active,
                $this->at('2026-01-01 00:00:00'),
                100,
                [],
                false,
                0,
            ),
        ]);
        $service = $this->service(
            new ParticipationGiveawayRepository([$giveaway]),
            new MemoryParticipationRepository(),
            $contexts,
            [$owner->value()=>['giveaway.view'=>true,'giveaway.enter'=>true]],
        );

        $this->expectException(GiveawayParticipationException::class);
        try {
            $service->enter($owner, $giveaway->giveawayId, str_repeat('d', 64), null, $this->at('2026-09-19 12:30:00'));
        } catch (GiveawayParticipationException $exception) {
            self::assertContains('self_entry', $exception->reasonCodes);
            throw $exception;
        }
    }

    public function testDuplicateDeviceAndCapacityAreEnforcedAcrossAccounts(): void
    {
        $owner = UserId::generate();
        $firstUser = UserId::generate();
        $secondUser = UserId::generate();
        $thirdUser = UserId::generate();
        $giveaway = $this->giveaway($owner, 1, 2);
        $giveaways = new ParticipationGiveawayRepository([$giveaway]);
        $participation = new MemoryParticipationRepository();
        $participation->savePolicy(new GiveawayEligibilityPolicy(
            $giveaway->giveawayId,
            0,
            0,
            true,
            [],
            GiveawayReferralRequirement::None,
            0,
            3,
            1,
        ), $this->at('2026-09-19 12:00:00'));
        $contexts = new MemoryEligibilityContexts([]);
        foreach ([$firstUser,$secondUser,$thirdUser] as $user) {
            $contexts->put(new GiveawayEligibilityContext(
                $user,
                UserStatus::Active,
                $this->at('2026-01-01 00:00:00'),
                0,
                [],
                false,
                0,
            ));
        }
        $permissions = [];
        foreach ([$firstUser,$secondUser,$thirdUser] as $user) {
            $permissions[$user->value()] = ['giveaway.view'=>true,'giveaway.enter'=>true];
        }
        $service = $this->service($giveaways, $participation, $contexts, $permissions);

        $service->enter($firstUser, $giveaway->giveawayId, str_repeat('1',64), str_repeat('9',64), $this->at('2026-09-19 12:30:00'));

        try {
            $service->enter($secondUser, $giveaway->giveawayId, str_repeat('2',64), str_repeat('9',64), $this->at('2026-09-19 12:31:00'));
            self::fail('Duplicate device should be rejected.');
        } catch (GiveawayParticipationException $exception) {
            self::assertContains('duplicate_device', $exception->reasonCodes);
        }

        $service->enter($secondUser, $giveaway->giveawayId, str_repeat('2',64), str_repeat('8',64), $this->at('2026-09-19 12:32:00'));

        try {
            $service->enter($thirdUser, $giveaway->giveawayId, str_repeat('3',64), str_repeat('7',64), $this->at('2026-09-19 12:33:00'));
            self::fail('Capacity should be rejected.');
        } catch (GiveawayParticipationException $exception) {
            self::assertContains('capacity_reached', $exception->reasonCodes);
        }
    }

    public function testEnterPermissionIsBackendAuthoritative(): void
    {
        $owner = UserId::generate();
        $actor = UserId::generate();
        $giveaway = $this->giveaway($owner, 1, 10);
        $service = $this->service(
            new ParticipationGiveawayRepository([$giveaway]),
            new MemoryParticipationRepository(),
            new MemoryEligibilityContexts([]),
            [$actor->value()=>['giveaway.view'=>true,'giveaway.enter'=>false]],
        );

        $this->expectException(PermissionDeniedException::class);
        $service->enter($actor, $giveaway->giveawayId, str_repeat('4',64), null, $this->at('2026-09-19 12:30:00'));
    }

    public function testPolicyMutationIsAuditedAndLocksAtStartTime(): void
    {
        $owner = UserId::generate();
        $scheduled = $this->giveaway(
            $owner,
            1,
            10,
            GiveawayState::Scheduled,
            $this->at('2026-09-19 13:00:00'),
            $this->at('2026-09-19 14:00:00'),
        );
        $audit = new ParticipationAuditRecorder();
        $service = $this->service(
            new ParticipationGiveawayRepository([$scheduled]),
            new MemoryParticipationRepository(),
            new MemoryEligibilityContexts([]),
            [$owner->value()=>['giveaway.create'=>true,'giveaway.manage'=>false]],
            $audit,
        );
        $policy = new GiveawayEligibilityPolicy(
            $scheduled->giveawayId,
            7,
            3,
            true,
            [],
            GiveawayReferralRequirement::None,
            0,
            3,
            1,
        );

        $service->savePolicy($owner, $policy, $this->at('2026-09-19 12:30:00'));
        self::assertCount(1, $audit->events);
        self::assertSame('giveaway.eligibility.update', $audit->events[0]->action->value());

        $this->expectException(GiveawayParticipationException::class);
        $service->savePolicy($owner, $policy, $this->at('2026-09-19 13:01:00'));
    }

    private function service(
        ParticipationGiveawayRepository $giveaways,
        MemoryParticipationRepository $participation,
        MemoryEligibilityContexts $contexts,
        array $permissions,
        ?ParticipationAuditRecorder $audit = null,
    ): GiveawayParticipationService {
        return new GiveawayParticipationService(
            new ParticipationDatabase(),
            $giveaways,
            $participation,
            $contexts,
            new PermissionAuthorizer(
                new PermissionEngine(new ParticipationPermissionRules($permissions)),
                new ParticipationAssignments(array_keys($permissions)),
            ),
            $audit ?? new ParticipationAuditRecorder(),
        );
    }

    private function giveaway(
        EntityId $owner,
        int $entriesPerUser,
        ?int $maxParticipants,
        GiveawayState $state = GiveawayState::Open,
        ?DateTimeImmutable $startsAt = null,
        ?DateTimeImmutable $endsAt = null,
    ): Giveaway {
        $startsAt ??= $this->at('2026-09-19 11:00:00');
        $endsAt ??= $this->at('2026-09-19 14:00:00');
        return new Giveaway(
            Giveaway::generateId(),
            $owner,
            'entry-test-' . bin2hex(random_bytes(4)),
            'Participation test',
            'Description',
            new GiveawayPrize('Prize', '', 1),
            'Terms',
            $startsAt,
            $endsAt,
            $entriesPerUser,
            $maxParticipants,
            $state,
            $this->at('2026-09-01 00:00:00'),
            $this->at('2026-09-01 00:00:00'),
        );
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

final class ParticipationGiveawayRepository implements GiveawayRepository
{
    /** @var array<string,Giveaway> */
    private array $items = [];

    /** @param list<Giveaway> $items */
    public function __construct(array $items)
    {
        foreach ($items as $item) $this->items[$item->giveawayId->value()] = $item;
    }

    public function find(EntityId $giveawayId): ?Giveaway
    {
        return $this->items[$giveawayId->value()] ?? null;
    }

    public function list(?EntityId $ownerUserId = null, bool $publicOnly = true, int $limit = 100): array
    {
        return array_slice(array_values($this->items), 0, $limit);
    }

    public function save(Giveaway $giveaway): void
    {
        $this->items[$giveaway->giveawayId->value()] = $giveaway;
    }

    public function dueTransitions(DateTimeImmutable $at, int $limit = 100): array
    {
        return [];
    }
}

final class MemoryParticipationRepository implements GiveawayParticipationRepository
{
    /** @var array<string,GiveawayEligibilityPolicy> */
    private array $policies = [];
    /** @var array<string,GiveawayEntry> */
    private array $entries = [];

    public function policy(EntityId $giveawayId): ?GiveawayEligibilityPolicy
    {
        return $this->policies[$giveawayId->value()] ?? null;
    }

    public function savePolicy(GiveawayEligibilityPolicy $policy, DateTimeImmutable $at): void
    {
        $this->policies[$policy->giveawayId->value()] = $policy;
    }

    public function lockGiveaway(EntityId $giveawayId): bool
    {
        return true;
    }

    public function entryForUser(EntityId $giveawayId, EntityId $userId): ?GiveawayEntry
    {
        return $this->entries[$giveawayId->value() . ':' . $userId->value()] ?? null;
    }

    public function participantCount(EntityId $giveawayId): int
    {
        return count(array_filter(
            $this->entries,
            static fn (GiveawayEntry $entry): bool => $entry->giveawayId->equals($giveawayId),
        ));
    }

    public function entriesForDraw(EntityId $giveawayId): array
    {
        $entries = array_values(array_filter(
            $this->entries,
            static fn (GiveawayEntry $entry): bool => $entry->giveawayId->equals($giveawayId),
        ));
        usort($entries, static fn (GiveawayEntry $a, GiveawayEntry $b): int =>
            [$a->userId->value(), $a->entryId->value()] <=> [$b->userId->value(), $b->entryId->value()]
        );
        return $entries;
    }

    public function fingerprintParticipantCount(EntityId $giveawayId, string $kind, string $fingerprint): int
    {
        return count(array_filter(
            $this->entries,
            static function (GiveawayEntry $entry) use ($giveawayId, $kind, $fingerprint): bool {
                if (!$entry->giveawayId->equals($giveawayId)) return false;
                return $kind === 'network'
                    ? hash_equals($entry->networkFingerprint, $fingerprint)
                    : ($entry->deviceFingerprint !== null && hash_equals($entry->deviceFingerprint, $fingerprint));
            },
        ));
    }

    public function saveEntry(GiveawayEntry $entry): void
    {
        $this->entries[$entry->giveawayId->value() . ':' . $entry->userId->value()] = $entry;
    }

    public function availableRoles(): array
    {
        return [new GiveawayEligibilityRoleOption(
            EntityId::fromString('11111111111111111111111111111111'),
            'Member',
        )];
    }
}

final class MemoryEligibilityContexts implements GiveawayEligibilityContextProvider
{
    /** @param array<string,GiveawayEligibilityContext> $contexts */
    public function __construct(private array $contexts)
    {
    }

    public function put(GiveawayEligibilityContext $context): void
    {
        $this->contexts[$context->userId->value()] = $context;
    }

    public function context(EntityId $userId): GiveawayEligibilityContext
    {
        return $this->contexts[$userId->value()]
            ?? throw new \RuntimeException('Test eligibility context missing.');
    }
}

final readonly class ParticipationPermissionRules implements PermissionRuleRepository
{
    /** @param array<string,array<string,bool>> $permissions */
    public function __construct(private array $permissions)
    {
    }

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

final readonly class ParticipationAssignments implements UserAccessAssignmentProvider
{
    /** @var array<string,true> */
    private array $users;

    /** @param list<string> $ids */
    public function __construct(array $ids)
    {
        $this->users = array_fill_keys($ids, true);
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        if (!isset($this->users[$userId->value()])) return null;
        return new UserAccessAssignment(
            $userId,
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
        );
    }
}

final class ParticipationAuditRecorder implements AuditRecorder
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

final class ParticipationDatabase implements TransactionalQueryExecutor
{
    private int $depth = 0;

    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->depth > 0; }

    public function transaction(Closure $callback): mixed
    {
        ++$this->depth;
        try {
            return $callback($this);
        } finally {
            --$this->depth;
        }
    }
}
