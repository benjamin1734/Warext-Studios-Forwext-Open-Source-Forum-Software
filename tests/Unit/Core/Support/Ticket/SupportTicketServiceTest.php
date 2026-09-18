<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Support\Ticket;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Support\Ticket\SupportCategory;
use Forwext\Core\Support\Ticket\SupportSlaMetadata;
use Forwext\Core\Support\Ticket\SupportTicket;
use Forwext\Core\Support\Ticket\SupportTicketOperationException;
use Forwext\Core\Support\Ticket\SupportTicketPriority;
use Forwext\Core\Support\Ticket\SupportTicketRepository;
use Forwext\Core\Support\Ticket\SupportTicketService;
use Forwext\Core\Support\Ticket\SupportTicketStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SupportTicketServiceTest extends TestCase
{
    public function testCreateUsesCategoryDefaultsAndSnapshotsSlaDeadlines(): void
    {
        $actor = $this->id('1');
        $repo = new SupportMemoryRepository();
        $repo->categories['general'] = new SupportCategory(
            'general',
            'General',
            '',
            SupportTicketPriority::Normal,
            60,
            120,
            10,
            true,
        );
        $service = $this->service($actor, [
            $actor->value() => ['support.ticket.create', 'support.ticket.view_own'],
        ], $repo);
        $now = $this->time('2026-09-18 15:00:00.000000');

        $ticket = $service->create('GENERAL', ' Need help ', $now);

        self::assertSame('general', $ticket->categoryKey);
        self::assertSame('Need help', $ticket->subject);
        self::assertSame(SupportTicketPriority::Normal, $ticket->priority);
        self::assertSame(SupportTicketStatus::Open, $ticket->status);
        self::assertSame($actor->value(), $ticket->requesterUserId?->value());
        self::assertSame('2026-09-18 16:00:00.000000', $ticket->sla->firstResponseDueAt?->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-09-18 17:00:00.000000', $ticket->sla->resolutionDueAt?->format('Y-m-d H:i:s.u'));
        self::assertSame(1, $ticket->version);
        self::assertCount(1, $repo->tickets);
    }

    public function testRequesterCannotReadAnotherUsersTicketWithoutViewAll(): void
    {
        $actor = $this->id('1');
        $repo = new SupportMemoryRepository();
        $ticket = $this->ticket($this->id('2'));
        $repo->tickets[$ticket->ticketId->value()] = $ticket;
        $service = $this->service($actor, [
            $actor->value() => ['support.ticket.view_own'],
        ], $repo);

        $this->expectException(PermissionDeniedException::class);
        $service->ticket($ticket->ticketId);
    }

    public function testManagerCanResolveThenReopenTicketAndSlaResolutionFollowsLifecycle(): void
    {
        $actor = $this->id('1');
        $repo = new SupportMemoryRepository();
        $ticket = $this->ticket($this->id('2'));
        $repo->tickets[$ticket->ticketId->value()] = $ticket;
        $service = $this->service($actor, [
            $actor->value() => ['support.ticket.manage', 'support.ticket.view_all'],
        ], $repo);

        $resolvedAt = $this->time('2026-09-18 16:00:00.000000');
        $resolved = $service->changeStatus($ticket->ticketId, SupportTicketStatus::Resolved, $resolvedAt);

        self::assertSame(SupportTicketStatus::Resolved, $resolved->status);
        self::assertSame($resolvedAt->format('c'), $resolved->sla->resolvedAt?->format('c'));
        self::assertNull($resolved->closedAt);

        $reopened = $service->changeStatus(
            $ticket->ticketId,
            SupportTicketStatus::Open,
            $this->time('2026-09-18 17:00:00.000000'),
        );

        self::assertSame(SupportTicketStatus::Open, $reopened->status);
        self::assertNull($reopened->sla->resolvedAt);
        self::assertNull($reopened->closedAt);
        self::assertSame(3, $reopened->version);
    }

    public function testManagerCannotAssignUserWithoutSupportStaffAccess(): void
    {
        $actor = $this->id('1');
        $assignee = $this->id('3');
        $repo = new SupportMemoryRepository();
        $ticket = $this->ticket($this->id('2'));
        $repo->tickets[$ticket->ticketId->value()] = $ticket;
        $service = $this->service($actor, [
            $actor->value() => ['support.ticket.assign', 'support.ticket.view_all'],
            $assignee->value() => [],
        ], $repo);

        $this->expectException(InvalidArgumentException::class);
        $service->assign($ticket->ticketId, $assignee);
    }

    public function testClosedTicketCannotJumpDirectlyToInProgress(): void
    {
        self::assertFalse(SupportTicketStatus::Closed->canTransitionTo(SupportTicketStatus::InProgress));
        self::assertTrue(SupportTicketStatus::Closed->canTransitionTo(SupportTicketStatus::Open));
    }

    public function testSlaBreachUsesRecordedResponseAndResolutionTimes(): void
    {
        $due = $this->time('2026-09-18 16:00:00.000000');
        $resolutionDue = $this->time('2026-09-18 18:00:00.000000');
        $sla = new SupportSlaMetadata(
            $due,
            $resolutionDue,
            $this->time('2026-09-18 16:05:00.000000'),
            $this->time('2026-09-18 17:30:00.000000'),
        );

        self::assertTrue($sla->firstResponseBreached($this->time('2026-09-18 20:00:00.000000')));
        self::assertFalse($sla->resolutionBreached($this->time('2026-09-18 20:00:00.000000')));
    }

    /**
     * @param array<string,list<string>> $permissions
     */
    private function service(
        EntityId $actor,
        array $permissions,
        SupportMemoryRepository $repo,
    ): SupportTicketService {
        $permissionRepository = new SupportPermissionRepository($permissions);
        $provider = new SupportAssignmentProvider(array_keys($permissions));
        $authorizer = new PermissionAuthorizer(new PermissionEngine($permissionRepository), $provider);

        return new SupportTicketService(
            new SupportTransactionDatabase(),
            $repo,
            new PermissionGate($authorizer, $actor),
            $authorizer,
        );
    }

    private function ticket(EntityId $requester): SupportTicket
    {
        $created = $this->time('2026-09-18 15:00:00.000000');
        return new SupportTicket(
            EntityId::fromString(str_repeat('a', 32)),
            'general',
            $requester,
            null,
            'Stored ticket',
            SupportTicketPriority::Normal,
            SupportTicketStatus::Open,
            new SupportSlaMetadata(
                $this->time('2026-09-18 16:00:00.000000'),
                $this->time('2026-09-18 18:00:00.000000'),
            ),
            null,
            $created,
            $created,
            1,
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class SupportMemoryRepository implements SupportTicketRepository
{
    /** @var array<string,SupportCategory> */
    public array $categories = [];
    /** @var array<string,SupportTicket> */
    public array $tickets = [];

    public function activeCategories(): array
    {
        return array_values(array_filter($this->categories, static fn (SupportCategory $c): bool => $c->active));
    }

    public function category(string $key): ?SupportCategory
    {
        return $this->categories[$key] ?? null;
    }

    public function saveCategory(SupportCategory $category): void
    {
        $this->categories[$category->key] = $category;
    }

    public function create(SupportTicket $ticket): void
    {
        $this->tickets[$ticket->ticketId->value()] = $ticket;
    }

    public function find(EntityId $ticketId): ?SupportTicket
    {
        return $this->tickets[$ticketId->value()] ?? null;
    }

    public function forRequester(EntityId $requesterUserId, int $limit = 50): array
    {
        return array_slice(array_values(array_filter(
            $this->tickets,
            static fn (SupportTicket $ticket): bool => $ticket->requesterUserId?->equals($requesterUserId) ?? false,
        )), 0, $limit);
    }

    public function activeQueue(int $limit = 100): array
    {
        return array_slice(array_values(array_filter(
            $this->tickets,
            static fn (SupportTicket $ticket): bool => $ticket->status->isActive(),
        )), 0, $limit);
    }

    public function assign(
        EntityId $ticketId,
        ?EntityId $assignedUserId,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket {
        $current = $this->versioned($ticketId, $expectedVersion);
        return $this->store(new SupportTicket(
            $current->ticketId,
            $current->categoryKey,
            $current->requesterUserId,
            $assignedUserId,
            $current->subject,
            $current->priority,
            $current->status,
            $current->sla,
            $current->closedAt,
            $current->createdAt,
            $now,
            $current->version + 1,
        ));
    }

    public function changePriority(
        EntityId $ticketId,
        SupportTicketPriority $priority,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket {
        $current = $this->versioned($ticketId, $expectedVersion);
        return $this->store(new SupportTicket(
            $current->ticketId,
            $current->categoryKey,
            $current->requesterUserId,
            $current->assignedUserId,
            $current->subject,
            $priority,
            $current->status,
            $current->sla,
            $current->closedAt,
            $current->createdAt,
            $now,
            $current->version + 1,
        ));
    }

    public function changeStatus(
        EntityId $ticketId,
        SupportTicketStatus $status,
        SupportSlaMetadata $sla,
        ?DateTimeImmutable $closedAt,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket {
        $current = $this->versioned($ticketId, $expectedVersion);
        return $this->store(new SupportTicket(
            $current->ticketId,
            $current->categoryKey,
            $current->requesterUserId,
            $current->assignedUserId,
            $current->subject,
            $current->priority,
            $status,
            $sla,
            $closedAt,
            $current->createdAt,
            $now,
            $current->version + 1,
        ));
    }

    public function markFirstResponse(
        EntityId $ticketId,
        DateTimeImmutable $firstRespondedAt,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket {
        $current = $this->versioned($ticketId, $expectedVersion);
        return $this->store(new SupportTicket(
            $current->ticketId,
            $current->categoryKey,
            $current->requesterUserId,
            $current->assignedUserId,
            $current->subject,
            $current->priority,
            $current->status,
            $current->sla->withFirstResponse($firstRespondedAt),
            $current->closedAt,
            $current->createdAt,
            $now,
            $current->version + 1,
        ));
    }

    private function versioned(EntityId $ticketId, int $expectedVersion): SupportTicket
    {
        $ticket = $this->find($ticketId) ?? throw new SupportTicketOperationException('Ticket missing.');
        if ($ticket->version !== $expectedVersion) {
            throw new SupportTicketOperationException('Stale ticket version.');
        }
        return $ticket;
    }

    private function store(SupportTicket $ticket): SupportTicket
    {
        $this->tickets[$ticket->ticketId->value()] = $ticket;
        return $ticket;
    }
}

final class SupportTransactionDatabase implements TransactionalQueryExecutor
{
    private bool $inside = false;

    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->inside; }

    public function transaction(Closure $callback): mixed
    {
        $before = $this->inside;
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = $before;
        }
    }
}

final readonly class SupportAssignmentProvider implements UserAccessAssignmentProvider
{
    /** @param list<string> $userIds */
    public function __construct(private array $userIds)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        if (!in_array($userId->value(), $this->userIds, true)) {
            return null;
        }
        return new UserAccessAssignment($userId, EntityId::fromString(str_repeat('f', 32)));
    }
}

final readonly class SupportPermissionRepository implements PermissionRuleRepository
{
    /** @param array<string,list<string>> $permissions */
    public function __construct(private array $permissions)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($nodeId !== null
            || !in_array($key->value(), $this->permissions[$assignment->userId()->value()] ?? [], true)
        ) {
            return [];
        }

        return [new PermissionRule(
            PermissionSubjectType::User,
            $assignment->userId(),
            PermissionEffect::Allow,
        )];
    }
}
