<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Support\Conversation;

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
use Forwext\Core\Support\Conversation\SupportCannedResponse;
use Forwext\Core\Support\Conversation\SupportConversationMessage;
use Forwext\Core\Support\Conversation\SupportConversationOperationException;
use Forwext\Core\Support\Conversation\SupportConversationRepository;
use Forwext\Core\Support\Conversation\SupportConversationService;
use Forwext\Core\Support\Conversation\SupportEscalationState;
use Forwext\Core\Support\Conversation\SupportHistoryVisibility;
use Forwext\Core\Support\Conversation\SupportMessageRole;
use Forwext\Core\Support\Conversation\SupportMessageVisibility;
use Forwext\Core\Support\Conversation\SupportTicketHistoryEntry;
use Forwext\Core\Support\Conversation\SupportTicketNotifier;
use Forwext\Core\Support\Conversation\SupportTicketRelation;
use Forwext\Core\Support\Conversation\SupportTicketRelationType;
use Forwext\Core\Support\Intake\SupportAttachmentRecord;
use Forwext\Core\Support\Intake\SupportContextLink;
use Forwext\Core\Support\Intake\SupportFieldDefinition;
use Forwext\Core\Support\Intake\SupportFieldValue;
use Forwext\Core\Support\Intake\SupportTicketIntakeRepository;
use Forwext\Core\Support\Ticket\SupportCategory;
use Forwext\Core\Support\Ticket\SupportSlaMetadata;
use Forwext\Core\Support\Ticket\SupportTicket;
use Forwext\Core\Support\Ticket\SupportTicketPriority;
use Forwext\Core\Support\Ticket\SupportTicketRepository;
use Forwext\Core\Support\Ticket\SupportTicketService;
use Forwext\Core\Support\Ticket\SupportTicketStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SupportConversationServiceTest extends TestCase
{
    public function testStaffCannedReplyMarksFirstResponseAndRequesterCannotSeeInternalNote(): void
    {
        $staff = $this->id('1');
        $requester = $this->id('2');
        $tickets = new ConversationTicketRepository($this->category());
        $ticket = $this->ticket($requester);
        $tickets->tickets[$ticket->ticketId->value()] = $ticket;
        $conversation = new ConversationMemoryRepository();
        $conversation->canned['more_info'] = new SupportCannedResponse(
            'more_info',
            'More info',
            'Please send more details.',
            true,
            10,
        );
        $notifier = new ConversationNotifier();
        $service = $this->service(
            $staff,
            [
                $staff->value()=>['support.ticket.view_all','support.ticket.reply_all','support.ticket.internal_note'],
                $requester->value()=>['support.ticket.view_own','support.ticket.reply_own'],
            ],
            $tickets,
            $conversation,
            new ConversationIntakeRepository(),
            $notifier,
        );
        $now = $this->time('2026-09-18 17:00:00.000000');

        $reply = $service->reply($ticket->ticketId, '', 'more_info', $now);
        $note = $service->internalNote($ticket->ticketId, 'staff only note', $now);

        self::assertSame('Please send more details.', $reply->body);
        self::assertSame('more_info', $reply->cannedResponseKeySnapshot);
        self::assertSame(SupportMessageVisibility::Internal, $note->visibility);
        self::assertSame($now->format('c'), $tickets->find($ticket->ticketId)?->sla->firstRespondedAt?->format('c'));
        self::assertSame(1, $notifier->staffReplies);

        $requesterService = $this->service(
            $requester,
            [
                $requester->value()=>['support.ticket.view_own','support.ticket.reply_own'],
                $staff->value()=>['support.ticket.view_all'],
            ],
            $tickets,
            $conversation,
            new ConversationIntakeRepository(),
            new ConversationNotifier(),
        );
        $view = $requesterService->view($ticket->ticketId);
        self::assertFalse($view->staffView);
        self::assertCount(1, $view->messages);
        self::assertSame(SupportMessageVisibility::Public, $view->messages[0]->visibility);
    }

    public function testRequesterReplyReopensResolvedTicketWithoutManagePermission(): void
    {
        $requester = $this->id('2');
        $tickets = new ConversationTicketRepository($this->category());
        $ticket = $this->ticket(
            $requester,
            SupportTicketStatus::Resolved,
            new SupportSlaMetadata(
                $this->time('2026-09-18 16:00:00.000000'),
                $this->time('2026-09-18 18:00:00.000000'),
                null,
                $this->time('2026-09-18 16:30:00.000000'),
            ),
        );
        $tickets->tickets[$ticket->ticketId->value()] = $ticket;
        $conversation = new ConversationMemoryRepository();
        $service = $this->service(
            $requester,
            [$requester->value()=>['support.ticket.view_own','support.ticket.reply_own']],
            $tickets,
            $conversation,
            new ConversationIntakeRepository(),
            new ConversationNotifier(),
        );

        $service->reply(
            $ticket->ticketId,
            'I still need help.',
            now: $this->time('2026-09-18 17:00:00.000000'),
        );

        $stored = $tickets->find($ticket->ticketId);
        self::assertSame(SupportTicketStatus::Open, $stored?->status);
        self::assertNull($stored?->sla->resolvedAt);
        self::assertCount(1, $conversation->history);
        self::assertSame(SupportHistoryVisibility::Public, $conversation->history[0]->visibility);
    }

    public function testMergeRejectsTicketsOwnedByDifferentRequesters(): void
    {
        $staff = $this->id('1');
        $tickets = new ConversationTicketRepository($this->category());
        $a = $this->ticket($this->id('2'), idSeed:'a');
        $b = $this->ticket($this->id('3'), idSeed:'b');
        $tickets->tickets[$a->ticketId->value()] = $a;
        $tickets->tickets[$b->ticketId->value()] = $b;
        $service = $this->service(
            $staff,
            [$staff->value()=>['support.ticket.view_all','support.ticket.merge']],
            $tickets,
            new ConversationMemoryRepository(),
            new ConversationIntakeRepository(),
            new ConversationNotifier(),
        );

        $this->expectException(SupportConversationOperationException::class);
        $service->merge($a->ticketId, $b->ticketId);
    }

    public function testMergeCopiesSourceConversationWithoutDeletingHistory(): void
    {
        $staff = $this->id('1');
        $requester = $this->id('2');
        $tickets = new ConversationTicketRepository($this->category());
        $source = $this->ticket($requester, idSeed:'a');
        $target = $this->ticket($requester, idSeed:'b');
        $tickets->tickets[$source->ticketId->value()] = $source;
        $tickets->tickets[$target->ticketId->value()] = $target;
        $conversation = new ConversationMemoryRepository();
        $sourceMessage = new SupportConversationMessage(
            $this->id('c'),
            $source->ticketId,
            $requester,
            SupportMessageRole::Requester,
            SupportMessageVisibility::Public,
            'merge this message',
            null,
            null,
            null,
            $this->time('2026-09-18 16:00:00.000000'),
        );
        $conversation->messages[$sourceMessage->messageId->value()] = $sourceMessage;
        $service = $this->service(
            $staff,
            [$staff->value()=>['support.ticket.view_all','support.ticket.merge']],
            $tickets,
            $conversation,
            new ConversationIntakeRepository(),
            new ConversationNotifier(),
        );

        $relation = $service->merge(
            $source->ticketId,
            $target->ticketId,
            $this->time('2026-09-18 17:00:00.000000'),
        );

        self::assertSame(SupportTicketRelationType::MergedInto, $relation->type);
        self::assertSame(SupportTicketStatus::Closed, $tickets->find($source->ticketId)?->status);

        $targetMessages = $conversation->messages($target->ticketId, true);
        self::assertCount(1, $targetMessages);
        self::assertSame('merge this message', $targetMessages[0]->body);
        self::assertSame($sourceMessage->messageId->value(), $targetMessages[0]->copiedFromMessageId?->value());

        $sourceMessages = $conversation->messages($source->ticketId, true);
        self::assertCount(1, $sourceMessages);
        self::assertSame($sourceMessage->messageId->value(), $sourceMessages[0]->messageId->value());
    }

    public function testSplitRejectsInternalNoteAndCopiesPublicMessageWithSourceReference(): void
    {
        $staff = $this->id('1');
        $requester = $this->id('2');
        $tickets = new ConversationTicketRepository($this->category());
        $source = $this->ticket($requester, idSeed:'a');
        $tickets->tickets[$source->ticketId->value()] = $source;
        $conversation = new ConversationMemoryRepository();
        $internal = new SupportConversationMessage(
            $this->id('c'),
            $source->ticketId,
            $staff,
            SupportMessageRole::Staff,
            SupportMessageVisibility::Internal,
            'internal',
            null,
            null,
            null,
            $this->time('2026-09-18 16:00:00.000000'),
        );
        $public = new SupportConversationMessage(
            $this->id('d'),
            $source->ticketId,
            $requester,
            SupportMessageRole::Requester,
            SupportMessageVisibility::Public,
            'separate this issue',
            null,
            null,
            null,
            $this->time('2026-09-18 16:05:00.000000'),
        );
        $conversation->messages[$internal->messageId->value()] = $internal;
        $conversation->messages[$public->messageId->value()] = $public;
        $intake = new ConversationIntakeRepository();
        $service = $this->service(
            $staff,
            [$staff->value()=>['support.ticket.view_all','support.ticket.split']],
            $tickets,
            $conversation,
            $intake,
            new ConversationNotifier(),
        );

        try {
            $service->split($source->ticketId, $internal->messageId, 'Unsafe split');
            self::fail('Internal notes must not be splittable into user-visible tickets.');
        } catch (SupportConversationOperationException) {
            self::assertTrue(true);
        }

        $created = $service->split(
            $source->ticketId,
            $public->messageId,
            'Separated issue',
            $this->time('2026-09-18 17:00:00.000000'),
        );

        self::assertSame($requester->value(), $created->requesterUserId?->value());
        self::assertSame('separate this issue', $intake->descriptions[$created->ticketId->value()] ?? null);
        $copied = array_values(array_filter(
            $conversation->messages,
            static fn (SupportConversationMessage $message): bool => $message->ticketId->equals($created->ticketId),
        ));
        self::assertCount(1, $copied);
        self::assertSame($public->messageId->value(), $copied[0]->copiedFromMessageId?->value());
        self::assertCount(1, $conversation->relations);
        self::assertSame(SupportTicketRelationType::SplitFrom, $conversation->relations[0]->type);
    }

    public function testEscalationMustIncreaseAndAssignmentCreatesStaffHistory(): void
    {
        $staff = $this->id('1');
        $assignee = $this->id('4');
        $requester = $this->id('2');
        $tickets = new ConversationTicketRepository($this->category());
        $ticket = $this->ticket($requester);
        $tickets->tickets[$ticket->ticketId->value()] = $ticket;
        $conversation = new ConversationMemoryRepository();
        $service = $this->service(
            $staff,
            [
                $staff->value()=>['support.ticket.view_all','support.ticket.escalate','support.ticket.assign'],
                $assignee->value()=>['support.ticket.view_all'],
            ],
            $tickets,
            $conversation,
            new ConversationIntakeRepository(),
            new ConversationNotifier(),
        );

        $state = $service->escalate($ticket->ticketId, 2, $this->time('2026-09-18 17:00:00.000000'));
        self::assertSame(2, $state->level);

        try {
            $service->escalate($ticket->ticketId, 2, $this->time('2026-09-18 17:05:00.000000'));
            self::fail('Escalation must be monotonic.');
        } catch (SupportConversationOperationException) {
            self::assertTrue(true);
        }

        $assigned = $service->assign($ticket->ticketId, $assignee, $this->time('2026-09-18 17:10:00.000000'));
        self::assertSame($assignee->value(), $assigned->assignedUserId?->value());
        self::assertCount(2, $conversation->history);
        self::assertSame(SupportHistoryVisibility::Staff, $conversation->history[1]->visibility);
    }

    /**
     * @param array<string,list<string>> $permissions
     */
    private function service(
        EntityId $actor,
        array $permissions,
        ConversationTicketRepository $tickets,
        ConversationMemoryRepository $conversation,
        ConversationIntakeRepository $intake,
        ConversationNotifier $notifier,
    ): SupportConversationService {
        $database = new ConversationTransactionDatabase();
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new ConversationPermissionRepository($permissions)),
            new ConversationAssignmentProvider(array_keys($permissions)),
        );
        $gate = new PermissionGate($authorizer, $actor);
        $ticketService = new SupportTicketService($database, $tickets, $gate, $authorizer);

        return new SupportConversationService(
            $database,
            $tickets,
            $ticketService,
            $intake,
            $conversation,
            $gate,
            $notifier,
        );
    }

    private function category(): SupportCategory
    {
        return new SupportCategory(
            'general',
            'General',
            '',
            SupportTicketPriority::Normal,
            60,
            120,
            10,
            true,
        );
    }

    private function ticket(
        EntityId $requester,
        SupportTicketStatus $status = SupportTicketStatus::Open,
        ?SupportSlaMetadata $sla = null,
        string $idSeed = 'a',
    ): SupportTicket {
        $created = $this->time('2026-09-18 15:00:00.000000');
        $sla ??= new SupportSlaMetadata(
            $this->time('2026-09-18 16:00:00.000000'),
            $this->time('2026-09-18 18:00:00.000000'),
        );
        return new SupportTicket(
            $this->id($idSeed),
            'general',
            $requester,
            null,
            'Stored ticket',
            SupportTicketPriority::Normal,
            $status,
            $sla,
            $status === SupportTicketStatus::Closed ? $created : null,
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

final class ConversationTicketRepository implements SupportTicketRepository
{
    /** @var array<string,SupportTicket> */
    public array $tickets = [];

    public function __construct(private SupportCategory $category) {}
    public function activeCategories(): array { return [$this->category]; }
    public function category(string $key): ?SupportCategory { return $key === $this->category->key ? $this->category : null; }
    public function saveCategory(SupportCategory $category): void {}
    public function create(SupportTicket $ticket): void { $this->tickets[$ticket->ticketId->value()] = $ticket; }
    public function find(EntityId $ticketId): ?SupportTicket { return $this->tickets[$ticketId->value()] ?? null; }
    public function forRequester(EntityId $requesterUserId, int $limit = 50): array { return []; }
    public function activeQueue(int $limit = 100): array { return []; }

    public function assign(EntityId $ticketId, ?EntityId $assignedUserId, int $expectedVersion, DateTimeImmutable $now): SupportTicket
    {
        $current = $this->versioned($ticketId, $expectedVersion);
        return $this->store(new SupportTicket(
            $current->ticketId,$current->categoryKey,$current->requesterUserId,$assignedUserId,$current->subject,
            $current->priority,$current->status,$current->sla,$current->closedAt,$current->createdAt,$now,$current->version+1,
        ));
    }

    public function changePriority(EntityId $ticketId, SupportTicketPriority $priority, int $expectedVersion, DateTimeImmutable $now): SupportTicket
    {
        $current = $this->versioned($ticketId, $expectedVersion);
        return $this->store(new SupportTicket(
            $current->ticketId,$current->categoryKey,$current->requesterUserId,$current->assignedUserId,$current->subject,
            $priority,$current->status,$current->sla,$current->closedAt,$current->createdAt,$now,$current->version+1,
        ));
    }

    public function changeStatus(EntityId $ticketId, SupportTicketStatus $status, SupportSlaMetadata $sla, ?DateTimeImmutable $closedAt, int $expectedVersion, DateTimeImmutable $now): SupportTicket
    {
        $current = $this->versioned($ticketId, $expectedVersion);
        return $this->store(new SupportTicket(
            $current->ticketId,$current->categoryKey,$current->requesterUserId,$current->assignedUserId,$current->subject,
            $current->priority,$status,$sla,$closedAt,$current->createdAt,$now,$current->version+1,
        ));
    }

    public function markFirstResponse(EntityId $ticketId, DateTimeImmutable $firstRespondedAt, int $expectedVersion, DateTimeImmutable $now): SupportTicket
    {
        $current = $this->versioned($ticketId, $expectedVersion);
        return $this->store(new SupportTicket(
            $current->ticketId,$current->categoryKey,$current->requesterUserId,$current->assignedUserId,$current->subject,
            $current->priority,$current->status,$current->sla->withFirstResponse($firstRespondedAt),$current->closedAt,
            $current->createdAt,$now,$current->version+1,
        ));
    }

    private function versioned(EntityId $ticketId, int $version): SupportTicket
    {
        $ticket = $this->find($ticketId) ?? throw new SupportConversationOperationException('Ticket missing.');
        if ($ticket->version !== $version) throw new SupportConversationOperationException('Stale ticket.');
        return $ticket;
    }

    private function store(SupportTicket $ticket): SupportTicket
    {
        $this->tickets[$ticket->ticketId->value()] = $ticket;
        return $ticket;
    }
}

final class ConversationMemoryRepository implements SupportConversationRepository
{
    /** @var array<string,SupportConversationMessage> */
    public array $messages = [];
    /** @var list<SupportTicketHistoryEntry> */
    public array $history = [];
    /** @var array<string,SupportCannedResponse> */
    public array $canned = [];
    /** @var array<string,SupportEscalationState> */
    public array $escalations = [];
    /** @var list<SupportTicketRelation> */
    public array $relations = [];

    public function appendMessage(SupportConversationMessage $message): void { $this->messages[$message->messageId->value()] = $message; }
    public function message(EntityId $messageId): ?SupportConversationMessage { return $this->messages[$messageId->value()] ?? null; }
    public function messages(EntityId $ticketId, bool $includeInternal, int $limit = 200): array
    {
        return array_slice(array_values(array_filter(
            $this->messages,
            static fn (SupportConversationMessage $m): bool => $m->ticketId->equals($ticketId)
                && ($includeInternal || $m->visibility === SupportMessageVisibility::Public),
        )),0,$limit);
    }
    public function appendHistory(SupportTicketHistoryEntry $entry): void { $this->history[] = $entry; }
    public function history(EntityId $ticketId, bool $includeStaff, int $limit = 200): array
    {
        return array_slice(array_values(array_filter(
            $this->history,
            static fn (SupportTicketHistoryEntry $h): bool => $h->ticketId->equals($ticketId)
                && ($includeStaff || $h->visibility === SupportHistoryVisibility::Public),
        )),0,$limit);
    }
    public function saveCannedResponse(SupportCannedResponse $response): void { $this->canned[$response->key] = $response; }
    public function cannedResponse(string $key): ?SupportCannedResponse { return $this->canned[$key] ?? null; }
    public function activeCannedResponses(): array { return array_values(array_filter($this->canned, static fn (SupportCannedResponse $r): bool => $r->active)); }
    public function escalation(EntityId $ticketId): ?SupportEscalationState { return $this->escalations[$ticketId->value()] ?? null; }
    public function setEscalation(SupportEscalationState $state): void { $this->escalations[$state->ticketId->value()] = $state; }
    public function addRelation(SupportTicketRelation $relation): void { $this->relations[] = $relation; }
    public function relations(EntityId $ticketId): array
    {
        return array_values(array_filter(
            $this->relations,
            static fn (SupportTicketRelation $r): bool => $r->sourceTicketId->equals($ticketId) || $r->targetTicketId->equals($ticketId),
        ));
    }
}

final class ConversationIntakeRepository implements SupportTicketIntakeRepository
{
    /** @var array<string,string> */
    public array $descriptions = [];
    /** @var array<string,SupportContextLink> */
    public array $contexts = [];
    public function activeFields(string $categoryKey): array { return []; }
    public function saveFieldDefinition(SupportFieldDefinition $definition): void {}
    public function saveIntake(EntityId $ticketId, string $description): void { $this->descriptions[$ticketId->value()] = $description; }
    public function description(EntityId $ticketId): ?string { return $this->descriptions[$ticketId->value()] ?? null; }
    public function saveFieldValues(EntityId $ticketId, array $values): void {}
    public function saveContext(EntityId $ticketId, SupportContextLink $context): void { $this->contexts[$ticketId->value()] = $context; }
    public function saveAttachment(SupportAttachmentRecord $attachment): void {}
    public function fieldValues(EntityId $ticketId): array { return []; }
    public function context(EntityId $ticketId): ?SupportContextLink { return $this->contexts[$ticketId->value()] ?? null; }
    public function attachments(EntityId $ticketId): array { return []; }
}

final class ConversationNotifier implements SupportTicketNotifier
{
    public int $staffReplies = 0;
    public int $requesterReplies = 0;
    public int $assignments = 0;
    public int $statuses = 0;
    public int $splits = 0;
    public function staffReply(SupportTicket $ticket, SupportConversationMessage $message): void { $this->staffReplies++; }
    public function requesterReply(SupportTicket $ticket, SupportConversationMessage $message): void { $this->requesterReplies++; }
    public function assigned(SupportTicket $ticket, EntityId $assigneeUserId): void { $this->assignments++; }
    public function statusChanged(SupportTicket $ticket): void { $this->statuses++; }
    public function splitCreated(SupportTicket $source, SupportTicket $created): void { $this->splits++; }
}

final class ConversationTransactionDatabase implements TransactionalQueryExecutor
{
    private bool $inside = false;
    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->inside; }
    public function transaction(Closure $callback): mixed
    {
        $before=$this->inside;$this->inside=true;
        try { return $callback($this); } finally { $this->inside=$before; }
    }
}

final readonly class ConversationAssignmentProvider implements UserAccessAssignmentProvider
{
    /** @param list<string> $ids */
    public function __construct(private array $ids) {}
    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return in_array($userId->value(), $this->ids, true)
            ? new UserAccessAssignment($userId, EntityId::fromString(str_repeat('f',32)))
            : null;
    }
}

final readonly class ConversationPermissionRepository implements PermissionRuleRepository
{
    /** @param array<string,list<string>> $permissions */
    public function __construct(private array $permissions) {}
    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }
    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($nodeId !== null || !in_array($key->value(), $this->permissions[$assignment->userId()->value()] ?? [], true)) {
            return [];
        }
        return [new PermissionRule(PermissionSubjectType::User, $assignment->userId(), PermissionEffect::Allow)];
    }
}
