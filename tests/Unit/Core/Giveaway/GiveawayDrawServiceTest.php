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
use Forwext\Core\Giveaway\Giveaway;
use Forwext\Core\Giveaway\GiveawayDraw;
use Forwext\Core\Giveaway\GiveawayDrawAlgorithm;
use Forwext\Core\Giveaway\GiveawayDrawCandidate;
use Forwext\Core\Giveaway\GiveawayDrawException;
use Forwext\Core\Giveaway\GiveawayDrawKind;
use Forwext\Core\Giveaway\GiveawayDrawRepository;
use Forwext\Core\Giveaway\GiveawayDrawService;
use Forwext\Core\Giveaway\GiveawayEligibilityPolicy;
use Forwext\Core\Giveaway\GiveawayEligibilityRoleOption;
use Forwext\Core\Giveaway\GiveawayEntry;
use Forwext\Core\Giveaway\GiveawayNotifier;
use Forwext\Core\Giveaway\GiveawayParticipationRepository;
use Forwext\Core\Giveaway\GiveawayPrize;
use Forwext\Core\Giveaway\GiveawayRepository;
use Forwext\Core\Giveaway\GiveawayState;
use Forwext\Core\Notification\Notification;
use Forwext\Core\Notification\NotificationChannel;
use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDelivery;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GiveawayDrawServiceTest extends TestCase
{
    public function testAlgorithmIsDeterministicAndSelfVerifying(): void
    {
        $algorithm = new GiveawayDrawAlgorithm();
        $candidates = [
            new GiveawayDrawCandidate(
                EntityId::fromString('11111111111111111111111111111111'),
                EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
                1,
            ),
            new GiveawayDrawCandidate(
                EntityId::fromString('22222222222222222222222222222222'),
                EntityId::fromString('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
                3,
            ),
        ];
        $seed = str_repeat('c', 64);

        $first = $algorithm->select($candidates, $seed);
        $second = $algorithm->select(array_reverse($candidates), $seed);

        self::assertSame($first->populationHash, $second->populationHash);
        self::assertSame($first->selectedTicket, $second->selectedTicket);
        self::assertSame($first->winner->userId->value(), $second->winner->userId->value());

        $draw = new GiveawayDraw(
            GiveawayDraw::generateId(),
            EntityId::fromString('33333333333333333333333333333333'),
            1,
            GiveawayDrawKind::Primary,
            null,
            null,
            $seed,
            $first->populationHash,
            $first->participantCount,
            $first->totalWeight,
            $first->selectedTicket,
            $first->winner->userId,
            $first->winner->entryId,
            $first->winner->weight,
            EntityId::fromString('dddddddddddddddddddddddddddddddd'),
            $this->at('2026-09-19 16:00:00'),
        );

        self::assertTrue($algorithm->verifies($draw, $candidates));
    }

    public function testPrimaryDrawIsAtomicAuditedNotifiedAndCannotRepeat(): void
    {
        [$service, $giveaway, $manager, $draws, $audit, $notifications] = $this->fixture();

        $draw = $service->draw($manager, $giveaway->giveawayId, $this->at('2026-09-19 16:00:00'));

        self::assertSame(1, $draw->sequence);
        self::assertSame(GiveawayDrawKind::Primary, $draw->kind);
        self::assertCount(1, $draws->history($giveaway->giveawayId));
        self::assertCount(2, $draws->population($draw->drawId));
        self::assertCount(1, $audit->events);
        self::assertSame('giveaway.draw', $audit->events[0]->action->value());
        self::assertCount(1, $notifications->notifications);
        self::assertSame(GiveawayNotifier::WINNER, $notifications->notifications[0]->typeKey);

        $proofs = $service->proof($manager, $giveaway->giveawayId);
        self::assertCount(1, $proofs);
        self::assertTrue($proofs[0]->verified);
        self::assertTrue($proofs[0]->current);

        $this->expectException(GiveawayDrawException::class);
        $service->draw($manager, $giveaway->giveawayId, $this->at('2026-09-19 16:01:00'));
    }

    public function testRedrawRequiresReasonExcludesPreviousWinnerAndPreservesProofChain(): void
    {
        [$service, $giveaway, $manager, $draws, $audit, $notifications] = $this->fixture();
        $primary = $service->draw($manager, $giveaway->giveawayId, $this->at('2026-09-19 16:00:00'));

        $redraw = $service->redraw(
            $manager,
            $giveaway->giveawayId,
            'Önceki kazanan ödül koşullarını sonradan ihlal etti.',
            $this->at('2026-09-19 16:05:00'),
        );

        self::assertSame(2, $redraw->sequence);
        self::assertSame(GiveawayDrawKind::Redraw, $redraw->kind);
        self::assertTrue($redraw->parentDrawId?->equals($primary->drawId) ?? false);
        self::assertFalse($redraw->winnerUserId->equals($primary->winnerUserId));
        self::assertCount(1, $draws->population($redraw->drawId), 'Previous winner must be excluded from redraw population.');
        self::assertCount(2, $audit->events);
        self::assertSame('giveaway.redraw', $audit->events[1]->action->value());
        self::assertCount(3, $notifications->notifications);
        self::assertSame(GiveawayNotifier::WINNER_REPLACED, $notifications->notifications[1]->typeKey);
        self::assertSame(GiveawayNotifier::WINNER, $notifications->notifications[2]->typeKey);

        $proofs = $service->proof($manager, $giveaway->giveawayId);
        self::assertCount(2, $proofs);
        self::assertTrue($proofs[0]->verified);
        self::assertFalse($proofs[0]->current);
        self::assertTrue($proofs[1]->verified);
        self::assertTrue($proofs[1]->current);

        $this->expectException(InvalidArgumentException::class);
        $service->redraw($manager, $giveaway->giveawayId, 'kısa', $this->at('2026-09-19 16:06:00'));
    }

    public function testWinnerSelectionRequiresManagePermissionAndClosedState(): void
    {
        [$service, $giveaway, $manager] = $this->fixture(false);

        $this->expectException(PermissionDeniedException::class);
        $service->draw($manager, $giveaway->giveawayId, $this->at('2026-09-19 16:00:00'));
    }

    /** @return array{GiveawayDrawService,Giveaway,EntityId,MemoryDrawRepository,DrawAuditRecorder,DrawNotificationRepository} */
    private function fixture(bool $manage = true): array
    {
        $database = new DrawDatabase();
        $owner = UserId::generate();
        $manager = UserId::generate();
        $userA = UserId::generate();
        $userB = UserId::generate();
        $giveaway = new Giveaway(
            Giveaway::generateId(),
            $owner,
            'draw-test-' . bin2hex(random_bytes(4)),
            'Draw test',
            'Closed giveaway',
            new GiveawayPrize('Prize', '', 1),
            'Terms',
            $this->at('2026-09-19 14:00:00'),
            $this->at('2026-09-19 15:00:00'),
            1,
            10,
            GiveawayState::Closed,
            $this->at('2026-09-01 00:00:00'),
            $this->at('2026-09-19 15:00:00'),
        );
        $giveaways = new DrawGiveawayRepository([$giveaway]);
        $participation = new DrawParticipationRepository($database, [
            new GiveawayEntry(
                EntityId::fromString('11111111111111111111111111111111'),
                $giveaway->giveawayId,
                $userA,
                1,
                str_repeat('a', 64),
                str_repeat('b', 64),
                $this->at('2026-09-19 14:10:00'),
            ),
            new GiveawayEntry(
                EntityId::fromString('22222222222222222222222222222222'),
                $giveaway->giveawayId,
                $userB,
                1,
                str_repeat('c', 64),
                str_repeat('d', 64),
                $this->at('2026-09-19 14:20:00'),
            ),
        ]);
        $draws = new MemoryDrawRepository();
        $audit = new DrawAuditRecorder();
        $notificationRepo = new DrawNotificationRepository();
        $registry = new NotificationRegistry();
        GiveawayNotifier::registerDefinitions($registry);

        $permissions = [
            $manager->value()=>[
                'giveaway.manage'=>$manage,
                'giveaway.view'=>true,
            ],
        ];

        $service = new GiveawayDrawService(
            $database,
            $giveaways,
            $participation,
            $draws,
            new PermissionAuthorizer(
                new PermissionEngine(new DrawPermissionRules($permissions)),
                new DrawAssignments(array_keys($permissions)),
            ),
            $audit,
            new GiveawayNotifier(new NotificationDispatcher($registry, $notificationRepo)),
            new GiveawayDrawAlgorithm(),
        );

        return [$service, $giveaway, $manager, $draws, $audit, $notificationRepo];
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

final class DrawDatabase implements TransactionalQueryExecutor
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

final class DrawGiveawayRepository implements GiveawayRepository
{
    /** @var array<string,Giveaway> */
    private array $items = [];

    /** @param list<Giveaway> $items */
    public function __construct(array $items)
    {
        foreach ($items as $item) $this->items[$item->giveawayId->value()] = $item;
    }

    public function find(EntityId $giveawayId): ?Giveaway { return $this->items[$giveawayId->value()] ?? null; }
    public function list(?EntityId $ownerUserId = null, bool $publicOnly = true, int $limit = 100): array { return array_values($this->items); }
    public function save(Giveaway $giveaway): void { $this->items[$giveaway->giveawayId->value()] = $giveaway; }
    public function dueTransitions(DateTimeImmutable $at, int $limit = 100): array { return []; }
}

final class DrawParticipationRepository implements GiveawayParticipationRepository
{
    /** @param list<GiveawayEntry> $entries */
    public function __construct(private DrawDatabase $database, private array $entries)
    {
    }

    public function policy(EntityId $giveawayId): ?GiveawayEligibilityPolicy { return null; }
    public function savePolicy(GiveawayEligibilityPolicy $policy, DateTimeImmutable $at): void {}
    public function lockGiveaway(EntityId $giveawayId): bool
    {
        if (!$this->database->inTransaction()) {
            throw new RuntimeException('Draw lock must be acquired inside a transaction.');
        }
        return true;
    }
    public function entryForUser(EntityId $giveawayId, EntityId $userId): ?GiveawayEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->giveawayId->equals($giveawayId) && $entry->userId->equals($userId)) return $entry;
        }
        return null;
    }
    public function participantCount(EntityId $giveawayId): int { return count($this->entriesForDraw($giveawayId)); }
    public function entriesForDraw(EntityId $giveawayId): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (GiveawayEntry $entry): bool => $entry->giveawayId->equals($giveawayId),
        ));
    }
    public function fingerprintParticipantCount(EntityId $giveawayId, string $kind, string $fingerprint): int { return 0; }
    public function saveEntry(GiveawayEntry $entry): void { $this->entries[] = $entry; }
    public function availableRoles(): array { return []; }
}

final class MemoryDrawRepository implements GiveawayDrawRepository
{
    /** @var array<string,list<GiveawayDraw>> */
    private array $draws = [];
    /** @var array<string,list<GiveawayDrawCandidate>> */
    private array $populations = [];

    public function latest(EntityId $giveawayId): ?GiveawayDraw
    {
        $history = $this->history($giveawayId);
        return $history === [] ? null : $history[count($history) - 1];
    }

    public function history(EntityId $giveawayId): array
    {
        return $this->draws[$giveawayId->value()] ?? [];
    }

    public function save(GiveawayDraw $draw, array $population): void
    {
        $this->draws[$draw->giveawayId->value()][] = $draw;
        $this->populations[$draw->drawId->value()] = array_values($population);
    }

    public function population(EntityId $drawId): array
    {
        return $this->populations[$drawId->value()] ?? [];
    }
}

final readonly class DrawPermissionRules implements PermissionRuleRepository
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

final readonly class DrawAssignments implements UserAccessAssignmentProvider
{
    /** @var array<string,true> */
    private array $users;

    /** @param list<string> $ids */
    public function __construct(array $ids) { $this->users = array_fill_keys($ids, true); }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        if (!isset($this->users[$userId->value()])) return null;
        return new UserAccessAssignment($userId, EntityId::fromString('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'));
    }
}

final class DrawAuditRecorder implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function append(AuditEvent $event): void { $this->events[] = $event; }
    public function mutate(AuditEvent $event, callable $mutation): mixed
    {
        $result = $mutation();
        $this->events[] = $event;
        return $result;
    }
}

final class DrawNotificationRepository implements NotificationRepository
{
    /** @var list<Notification> */
    public array $notifications = [];
    /** @var array<string,Notification> */
    private array $dedupe = [];

    public function withRecipientLock(EntityId $recipientUserId, Closure $callback): mixed { return $callback(); }
    public function findByDedupe(EntityId $recipientUserId, string $dedupeKey): ?Notification
    {
        return $this->dedupe[$recipientUserId->value() . ':' . $dedupeKey] ?? null;
    }
    public function findOpenGroup(EntityId $recipientUserId, string $typeKey, string $groupKey): ?Notification { return null; }
    public function insert(Notification $notification, ?string $groupKey): void { $this->notifications[] = $notification; }
    public function incrementGroup(EntityId $notificationId, string $title, string $body, ?string $actionPath, array $payload, bool $inAppVisible, DateTimeImmutable $now): Notification
    {
        throw new RuntimeException('Grouping is not used by giveaway draw tests.');
    }
    public function rememberDedupe(EntityId $recipientUserId, string $dedupeKey, EntityId $notificationId, DateTimeImmutable $now): void
    {
        foreach ($this->notifications as $notification) {
            if ($notification->id->equals($notificationId)) {
                $this->dedupe[$recipientUserId->value() . ':' . $dedupeKey] = $notification;
                return;
            }
        }
    }
    public function preference(EntityId $userId, string $categoryKey, NotificationChannel $channel): ?bool { return null; }
    public function setPreference(EntityId $userId, string $categoryKey, NotificationChannel $channel, bool $enabled, DateTimeImmutable $now): void {}
    public function queueDelivery(EntityId $notificationId, NotificationChannel $channel, DateTimeImmutable $now): void {}
    public function dueDeliveries(DateTimeImmutable $now, int $limit): array { return []; }
    public function markDeliverySent(EntityId $notificationId, NotificationChannel $channel, DateTimeImmutable $now): void {}
    public function markDeliveryFailed(EntityId $notificationId, NotificationChannel $channel, int $attempts, ?DateTimeImmutable $nextAttemptAt, string $errorCode): void {}
    public function inbox(EntityId $userId, int $limit, int $offset): array { return []; }
    public function unreadCount(EntityId $userId): int { return 0; }
    public function markRead(EntityId $userId, EntityId $notificationId, DateTimeImmutable $now): bool { return false; }
}
