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
use Forwext\Core\Giveaway\GiveawayException;
use Forwext\Core\Giveaway\GiveawayPrize;
use Forwext\Core\Giveaway\GiveawayRepository;
use Forwext\Core\Giveaway\GiveawayService;
use Forwext\Core\Giveaway\GiveawayState;
use Forwext\Core\Search\Lifecycle\SearchIndexChange;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GiveawayServiceTest extends TestCase
{
    public function testEntityValidatesScheduleAndCalculatesPublishedState(): void
    {
        $owner = UserId::generate();
        $giveaway = $this->giveaway(
            $owner,
            $this->at('2026-09-19 13:00:00'),
            $this->at('2026-09-19 14:00:00'),
        );

        self::assertSame(GiveawayState::Scheduled, $giveaway->expectedPublishedState($this->at('2026-09-19 12:59:59')));
        self::assertSame(GiveawayState::Open, $giveaway->expectedPublishedState($this->at('2026-09-19 13:00:00')));
        self::assertSame(GiveawayState::Closed, $giveaway->expectedPublishedState($this->at('2026-09-19 14:00:00')));

        $this->expectException(InvalidArgumentException::class);
        $this->giveaway(
            $owner,
            $this->at('2026-09-19 14:00:00'),
            $this->at('2026-09-19 14:00:00'),
        );
    }

    public function testCreatePermissionIsBackendAuthoritative(): void
    {
        $actor = UserId::generate();
        $repo = new MemoryGiveawayRepository();
        $service = $this->service($repo, [$actor->value() => ['giveaway.view' => true]]);

        $this->expectException(PermissionDeniedException::class);
        $service->saveDraft(
            $actor,
            $this->giveaway($actor, $this->at('2026-09-19 13:00:00'), $this->at('2026-09-19 14:00:00')),
            $this->at('2026-09-19 12:00:00'),
        );
    }

    public function testOwnerLifecycleMovesDraftToScheduledOpenAndClosed(): void
    {
        $owner = UserId::generate();
        $repo = new MemoryGiveawayRepository();
        $audit = new MemoryGiveawayAudit();
        $search = new MemoryGiveawaySearchChanges();
        $service = $this->service(
            $repo,
            [$owner->value() => ['giveaway.view' => true, 'giveaway.create' => true]],
            $audit,
            $search,
        );

        $draft = $service->saveDraft(
            $owner,
            $this->giveaway($owner, $this->at('2026-09-19 13:00:00'), $this->at('2026-09-19 14:00:00')),
            $this->at('2026-09-19 12:00:00'),
        );
        self::assertSame(GiveawayState::Draft, $draft->state);

        $scheduled = $service->publish($owner, $draft->giveawayId, $this->at('2026-09-19 12:05:00'));
        self::assertSame(GiveawayState::Scheduled, $scheduled->state);

        self::assertSame(1, $service->syncDue($this->at('2026-09-19 13:01:00'), 100));
        self::assertSame(GiveawayState::Open, $repo->find($draft->giveawayId)?->state);

        self::assertSame(1, $service->syncDue($this->at('2026-09-19 14:01:00'), 100));
        self::assertSame(GiveawayState::Closed, $repo->find($draft->giveawayId)?->state);

        self::assertCount(2, $audit->events);
        self::assertSame('giveaway.create', $audit->events[0]->action->value());
        self::assertSame('giveaway.publish', $audit->events[1]->action->value());
        self::assertCount(4, $search->records);
    }

    public function testOwnershipBoundaryAndManageOverrideAreEnforced(): void
    {
        $owner = UserId::generate();
        $attacker = UserId::generate();
        $manager = UserId::generate();
        $repo = new MemoryGiveawayRepository();
        $existing = $this->giveaway(
            $owner,
            $this->at('2026-09-19 13:00:00'),
            $this->at('2026-09-19 14:00:00'),
        );
        $repo->save($existing);

        $service = $this->service($repo, [
            $owner->value() => ['giveaway.view' => true, 'giveaway.create' => true],
            $attacker->value() => ['giveaway.view' => true, 'giveaway.create' => true],
            $manager->value() => ['giveaway.view' => true, 'giveaway.manage' => true],
        ]);

        try {
            $service->saveDraft(
                $attacker,
                $this->copy($existing, GiveawayState::Draft, 'Unauthorized edit'),
                $this->at('2026-09-19 12:10:00'),
            );
            self::fail('Foreign owner edit should be denied.');
        } catch (PermissionDeniedException) {
            self::assertSame('Original title', $repo->find($existing->giveawayId)?->title);
        }

        self::assertCount(1, $service->manageable($manager));
        $cancelled = $service->cancel($manager, $existing->giveawayId, $this->at('2026-09-19 12:15:00'));
        self::assertSame(GiveawayState::Cancelled, $cancelled->state);
    }

    public function testOpenGiveawayCannotBeRewrittenAsDraft(): void
    {
        $owner = UserId::generate();
        $repo = new MemoryGiveawayRepository();
        $service = $this->service($repo, [
            $owner->value() => ['giveaway.view' => true, 'giveaway.create' => true],
        ]);

        $open = $this->copy(
            $this->giveaway(
                $owner,
                $this->at('2026-09-19 11:00:00'),
                $this->at('2026-09-19 14:00:00'),
            ),
            GiveawayState::Open,
            'Original title',
        );
        $repo->save($open);

        $this->expectException(GiveawayException::class);
        $service->saveDraft(
            $owner,
            $this->copy($open, GiveawayState::Draft, 'Changed'),
            $this->at('2026-09-19 12:20:00'),
        );
    }

    private function service(
        MemoryGiveawayRepository $repo,
        array $permissions,
        ?MemoryGiveawayAudit $audit = null,
        ?MemoryGiveawaySearchChanges $search = null,
    ): GiveawayService {
        return new GiveawayService(
            new MemoryGiveawayDatabase(),
            $repo,
            new PermissionAuthorizer(
                new PermissionEngine(new MemoryGiveawayPermissionRules($permissions)),
                new MemoryGiveawayAssignments(array_keys($permissions)),
            ),
            $audit ?? new MemoryGiveawayAudit(),
            $search ?? new MemoryGiveawaySearchChanges(),
        );
    }

    private function giveaway(
        EntityId $owner,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
    ): Giveaway {
        $created = $this->at('2026-09-19 12:00:00');
        return new Giveaway(
            Giveaway::generateId(),
            $owner,
            'community-giveaway-' . substr(bin2hex(random_bytes(8)), 0, 8),
            'Original title',
            'Giveaway description',
            new GiveawayPrize('Forwext prize', 'Prize details', 1),
            'Community members may participate.',
            $startsAt,
            $endsAt,
            1,
            500,
            GiveawayState::Draft,
            $created,
            $created,
        );
    }

    private function copy(Giveaway $source, GiveawayState $state, string $title): Giveaway
    {
        return new Giveaway(
            $source->giveawayId,
            $source->ownerUserId,
            $source->slug,
            $title,
            $source->description,
            $source->prize,
            $source->participationTerms,
            $source->startsAt,
            $source->endsAt,
            $source->entriesPerUser,
            $source->maxParticipants,
            $state,
            $source->createdAt,
            $source->updatedAt,
        );
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

final class MemoryGiveawayRepository implements GiveawayRepository
{
    /** @var array<string,Giveaway> */
    private array $items = [];

    public function find(EntityId $giveawayId): ?Giveaway
    {
        return $this->items[$giveawayId->value()] ?? null;
    }

    public function list(?EntityId $ownerUserId = null, bool $publicOnly = true, int $limit = 100): array
    {
        $items = array_values(array_filter(
            $this->items,
            static fn (Giveaway $item): bool =>
                ($ownerUserId === null || $item->ownerUserId->equals($ownerUserId))
                && (!$publicOnly || in_array(
                    $item->state,
                    [GiveawayState::Scheduled, GiveawayState::Open, GiveawayState::Closed],
                    true,
                )),
        ));
        return array_slice($items, 0, $limit);
    }

    public function save(Giveaway $giveaway): void
    {
        $this->items[$giveaway->giveawayId->value()] = $giveaway;
    }

    public function dueTransitions(DateTimeImmutable $at, int $limit = 100): array
    {
        $items = array_values(array_filter(
            $this->items,
            static fn (Giveaway $item): bool =>
                ($item->state === GiveawayState::Scheduled && $item->startsAt <= $at)
                || ($item->state === GiveawayState::Open && $item->endsAt <= $at),
        ));
        return array_slice($items, 0, $limit);
    }
}

final readonly class MemoryGiveawayPermissionRules implements PermissionRuleRepository
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

final readonly class MemoryGiveawayAssignments implements UserAccessAssignmentProvider
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
        if (!isset($this->users[$userId->value()])) {
            return null;
        }
        return new UserAccessAssignment(
            $userId,
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
        );
    }
}

final class MemoryGiveawayAudit implements AuditRecorder
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

final class MemoryGiveawaySearchChanges implements SearchIndexChangeStore
{
    /** @var list<array{type:string,id:string}> */
    public array $records = [];

    public function record(string $documentType, string $documentId): void
    {
        $this->records[] = ['type' => $documentType, 'id' => $documentId];
    }

    public function claimDue(DateTimeImmutable $now, int $limit, int $leaseSeconds = 120): array
    {
        return [];
    }

    public function acknowledge(SearchIndexChange $change): bool
    {
        return true;
    }

    public function retry(
        SearchIndexChange $change,
        int $attempts,
        DateTimeImmutable $availableAt,
        string $errorCode,
    ): bool {
        return true;
    }
}

final class MemoryGiveawayDatabase implements TransactionalQueryExecutor
{
    private int $depth = 0;

    public function execute(CompiledQuery $query): int
    {
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return null;
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

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
