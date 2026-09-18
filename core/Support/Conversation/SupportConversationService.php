<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Support\Intake\SupportTicketIntakeRepository;
use Forwext\Core\Support\Ticket\SupportSlaMetadata;
use Forwext\Core\Support\Ticket\SupportTicket;
use Forwext\Core\Support\Ticket\SupportTicketRepository;
use Forwext\Core\Support\Ticket\SupportTicketService;
use Forwext\Core\Support\Ticket\SupportTicketStatus;
use InvalidArgumentException;
use Throwable;

final readonly class SupportConversationService
{
    public const REPLY_ALL_PERMISSION = 'support.ticket.reply_all';
    public const INTERNAL_NOTE_PERMISSION = 'support.ticket.internal_note';
    public const ESCALATE_PERMISSION = 'support.ticket.escalate';
    public const MERGE_PERMISSION = 'support.ticket.merge';
    public const SPLIT_PERMISSION = 'support.ticket.split';
    public const CANNED_MANAGE_PERMISSION = 'support.canned_response.manage';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private SupportTicketRepository $tickets,
        private SupportTicketService $ticketService,
        private SupportTicketIntakeRepository $intake,
        private SupportConversationRepository $conversation,
        private PermissionGate $gate,
        private SupportTicketNotifier $notifier = new NullSupportTicketNotifier(),
    ) {
    }

    public function view(EntityId $ticketId): SupportConversationView
    {
        $ticket = $this->ticketService->ticket($ticketId);
        $staff = $this->gate->allows(PermissionKey::fromString(SupportTicketService::VIEW_ALL_PERMISSION));
        $canned = $staff && $this->gate->allows(PermissionKey::fromString(self::REPLY_ALL_PERMISSION))
            ? $this->conversation->activeCannedResponses()
            : [];

        return new SupportConversationView(
            $ticket,
            $this->conversation->messages($ticketId, $staff),
            $this->conversation->history($ticketId, $staff),
            $this->conversation->relations($ticketId),
            $staff ? $this->conversation->escalation($ticketId) : null,
            $canned,
            $staff,
        );
    }

    public function reply(
        EntityId $ticketId,
        string $body,
        ?string $cannedResponseKey = null,
        ?DateTimeImmutable $now = null,
    ): SupportConversationMessage {
        $ticket = $this->ticketService->ticket($ticketId);
        $actorIsRequester = $ticket->isRequester($this->gate->actorId());

        if ($actorIsRequester) {
            $this->gate->require(PermissionKey::fromString(SupportTicketService::VIEW_OWN_PERMISSION));
            $this->gate->require(PermissionKey::fromString('support.ticket.reply_own'));
            if ($cannedResponseKey !== null) {
                throw new InvalidArgumentException('Requesters cannot attach canned-response metadata.');
            }
        } else {
            $this->gate->require(PermissionKey::fromString(self::REPLY_ALL_PERMISSION));
        }

        if ($ticket->status === SupportTicketStatus::Closed) {
            throw new SupportConversationOperationException('Closed support tickets must be reopened before replying.');
        }

        $now = self::utc($now);
        $canned = null;
        if (!$actorIsRequester && $cannedResponseKey !== null) {
            $canned = $this->conversation->cannedResponse(strtolower(trim($cannedResponseKey)));
            if ($canned === null || !$canned->active) {
                throw new InvalidArgumentException('Canned response is unavailable.');
            }
            if (trim($body) === '') {
                $body = $canned->body;
            }
        }

        $message = new SupportConversationMessage(
            SupportConversationMessage::generateId(),
            $ticketId,
            $this->gate->actorId(),
            $actorIsRequester ? SupportMessageRole::Requester : SupportMessageRole::Staff,
            SupportMessageVisibility::Public,
            trim($body),
            $canned?->key,
            $canned?->title,
            null,
            $now,
        );

        $after = $this->database->transaction(function () use (
            $ticket,
            $message,
            $actorIsRequester,
            $now,
        ): SupportTicket {
            $current = $ticket;
            if ($current->status === SupportTicketStatus::Resolved) {
                $current = $this->reopenForReply($current, $now);
            }

            $this->conversation->appendMessage($message);

            if (!$actorIsRequester && $current->sla->firstRespondedAt === null) {
                $current = $this->tickets->markFirstResponse(
                    $current->ticketId,
                    $now,
                    $current->version,
                    $now,
                );
                $this->appendHistory(
                    $current->ticketId,
                    SupportHistoryEventType::FirstResponse,
                    SupportHistoryVisibility::Staff,
                    ['message_id'=>$message->messageId->value()],
                    $now,
                );
            }
            return $current;
        });

        if ($actorIsRequester) {
            $this->safeNotify(fn () => $this->notifier->requesterReply($after, $message));
        } else {
            $this->safeNotify(fn () => $this->notifier->staffReply($after, $message));
        }
        return $message;
    }

    public function internalNote(
        EntityId $ticketId,
        string $body,
        ?DateTimeImmutable $now = null,
    ): SupportConversationMessage {
        $this->gate->require(PermissionKey::fromString(self::INTERNAL_NOTE_PERMISSION));
        $ticket = $this->ticketService->ticket($ticketId);
        if (!$this->gate->allows(PermissionKey::fromString(SupportTicketService::VIEW_ALL_PERMISSION))) {
            throw new SupportConversationOperationException('Internal notes require staff ticket access.');
        }

        $message = new SupportConversationMessage(
            SupportConversationMessage::generateId(),
            $ticket->ticketId,
            $this->gate->actorId(),
            SupportMessageRole::Staff,
            SupportMessageVisibility::Internal,
            trim($body),
            null,
            null,
            null,
            self::utc($now),
        );
        $this->conversation->appendMessage($message);
        return $message;
    }

    public function saveCannedResponse(SupportCannedResponse $response): void
    {
        $this->gate->require(PermissionKey::fromString(self::CANNED_MANAGE_PERMISSION));
        $this->conversation->saveCannedResponse($response);
    }

    public function assign(
        EntityId $ticketId,
        ?EntityId $assigneeUserId,
        ?DateTimeImmutable $now = null,
    ): SupportTicket {
        $now = self::utc($now);
        [$before, $after] = $this->database->transaction(function () use ($ticketId, $assigneeUserId, $now): array {
            $before = $this->ticketService->ticket($ticketId);
            $after = $this->ticketService->assign($ticketId, $assigneeUserId, $now);
            if ($before->assignedUserId?->value() !== $after->assignedUserId?->value()) {
                $this->appendHistory(
                    $ticketId,
                    SupportHistoryEventType::Assigned,
                    SupportHistoryVisibility::Staff,
                    [
                        'from_user_id'=>$before->assignedUserId?->value(),
                        'to_user_id'=>$after->assignedUserId?->value(),
                    ],
                    $now,
                );
            }
            return [$before, $after];
        });

        if ($assigneeUserId !== null && $before->assignedUserId?->value() !== $assigneeUserId->value()) {
            $this->safeNotify(fn () => $this->notifier->assigned($after, $assigneeUserId));
        }
        return $after;
    }

    public function changeStatus(
        EntityId $ticketId,
        SupportTicketStatus $status,
        ?DateTimeImmutable $now = null,
    ): SupportTicket {
        $now = self::utc($now);
        [$before, $after] = $this->database->transaction(function () use ($ticketId, $status, $now): array {
            $before = $this->ticketService->ticket($ticketId);
            $after = $this->ticketService->changeStatus($ticketId, $status, $now);
            if ($before->status !== $after->status) {
                $this->appendHistory(
                    $ticketId,
                    SupportHistoryEventType::StatusChanged,
                    SupportHistoryVisibility::Public,
                    ['from'=>$before->status->value,'to'=>$after->status->value],
                    $now,
                );
            }
            return [$before, $after];
        });

        if ($before->status !== $after->status) {
            $this->safeNotify(fn () => $this->notifier->statusChanged($after));
        }
        return $after;
    }

    public function escalate(
        EntityId $ticketId,
        int $level,
        ?DateTimeImmutable $now = null,
    ): SupportEscalationState {
        $this->gate->require(PermissionKey::fromString(self::ESCALATE_PERMISSION));
        $now = self::utc($now);

        return $this->database->transaction(function () use ($ticketId, $level, $now): SupportEscalationState {
            $ticket = $this->ticketService->ticket($ticketId);
            if (!$ticket->status->isActive()) {
                throw new SupportConversationOperationException('Only active support tickets may be escalated.');
            }
            $current = $this->conversation->escalation($ticketId);
            if ($current !== null && $level <= $current->level) {
                throw new SupportConversationOperationException('Escalation level must increase.');
            }
            $state = new SupportEscalationState($ticketId, $level, $this->gate->actorId(), $now);
            $this->conversation->setEscalation($state);
            $this->appendHistory(
                $state->ticketId,
                SupportHistoryEventType::Escalated,
                SupportHistoryVisibility::Staff,
                ['from_level'=>$current?->level ?? 0,'to_level'=>$state->level],
                $now,
            );
            return $state;
        });
    }

    public function merge(
        EntityId $sourceTicketId,
        EntityId $targetTicketId,
        ?DateTimeImmutable $now = null,
    ): SupportTicketRelation {
        $this->gate->require(PermissionKey::fromString(self::MERGE_PERMISSION));
        if ($sourceTicketId->equals($targetTicketId)) {
            throw new InvalidArgumentException('A support ticket cannot be merged into itself.');
        }

        $source = $this->ticketService->ticket($sourceTicketId);
        $target = $this->ticketService->ticket($targetTicketId);
        if (!$source->status->isActive() || !$target->status->isActive()) {
            throw new SupportConversationOperationException('Merge requires two active support tickets.');
        }
        if ($source->requesterUserId?->value() !== $target->requesterUserId?->value()) {
            throw new SupportConversationOperationException('Tickets from different requesters cannot be merged.');
        }

        $now = self::utc($now);
        $relation = new SupportTicketRelation(
            SupportTicketRelation::generateId(),
            SupportTicketRelationType::MergedInto,
            $sourceTicketId,
            $targetTicketId,
            $this->gate->actorId(),
            $now,
        );

        $sourceMessages = $this->conversation->messages($source->ticketId, true, 500);
        if (count($sourceMessages) >= 500) {
            throw new SupportConversationOperationException(
                'Source ticket conversation is too large for one safe merge operation.',
            );
        }

        $this->database->transaction(function () use ($source, $target, $relation, $sourceMessages, $now): void {
            $closed = $this->tickets->changeStatus(
                $source->ticketId,
                SupportTicketStatus::Closed,
                $source->sla->withResolved($source->sla->resolvedAt ?? $now),
                $now,
                $source->version,
                $now,
            );
            $this->appendHistory(
                $source->ticketId,
                SupportHistoryEventType::StatusChanged,
                SupportHistoryVisibility::Public,
                ['from'=>$source->status->value,'to'=>$closed->status->value],
                $now,
            );

            foreach ($sourceMessages as $sourceMessage) {
                $this->conversation->appendMessage(new SupportConversationMessage(
                    SupportConversationMessage::generateId(),
                    $target->ticketId,
                    $sourceMessage->authorUserId,
                    $sourceMessage->authorRole,
                    $sourceMessage->visibility,
                    $sourceMessage->body,
                    $sourceMessage->cannedResponseKeySnapshot,
                    $sourceMessage->cannedResponseTitleSnapshot,
                    $sourceMessage->messageId,
                    $now,
                ));
            }

            $this->conversation->addRelation($relation);
            $this->appendHistory(
                $source->ticketId,
                SupportHistoryEventType::Merged,
                SupportHistoryVisibility::Public,
                ['target_ticket_id'=>$target->ticketId->value()],
                $now,
            );
            $this->appendHistory(
                $target->ticketId,
                SupportHistoryEventType::Merged,
                SupportHistoryVisibility::Public,
                [
                    'source_ticket_id'=>$source->ticketId->value(),
                    'copied_message_count'=>count($sourceMessages),
                ],
                $now,
            );
        });

        return $relation;
    }

    public function split(
        EntityId $sourceTicketId,
        EntityId $messageId,
        string $newSubject,
        ?DateTimeImmutable $now = null,
    ): SupportTicket {
        $this->gate->require(PermissionKey::fromString(self::SPLIT_PERMISSION));
        $source = $this->ticketService->ticket($sourceTicketId);
        $message = $this->conversation->message($messageId)
            ?? throw new SupportConversationOperationException('Split source message was not found.');
        if (!$message->ticketId->equals($sourceTicketId) || $message->visibility !== SupportMessageVisibility::Public) {
            throw new SupportConversationOperationException('Only a public message from the source ticket may be split.');
        }

        $newSubject = trim($newSubject);
        if ($newSubject === '' || strlen($newSubject) > 200) {
            throw new InvalidArgumentException('Split ticket subject must contain 1-200 UTF-8 bytes.');
        }
        $category = $this->tickets->category($source->categoryKey)
            ?? throw new SupportConversationOperationException('Source ticket category is unavailable.');
        $now = self::utc($now);
        $created = new SupportTicket(
            SupportTicket::generateId(),
            $source->categoryKey,
            $source->requesterUserId,
            $source->assignedUserId,
            $newSubject,
            $source->priority,
            SupportTicketStatus::Open,
            new SupportSlaMetadata(
                self::dueAt($now, $category->firstResponseMinutes),
                self::dueAt($now, $category->resolutionMinutes),
            ),
            null,
            $now,
            $now,
            1,
        );
        $copied = new SupportConversationMessage(
            SupportConversationMessage::generateId(),
            $created->ticketId,
            $message->authorUserId,
            $message->authorRole,
            SupportMessageVisibility::Public,
            $message->body,
            $message->cannedResponseKeySnapshot,
            $message->cannedResponseTitleSnapshot,
            $message->messageId,
            $now,
        );
        $relation = new SupportTicketRelation(
            SupportTicketRelation::generateId(),
            SupportTicketRelationType::SplitFrom,
            $source->ticketId,
            $created->ticketId,
            $this->gate->actorId(),
            $now,
        );

        $this->database->transaction(function () use ($source, $created, $copied, $relation, $message, $now): void {
            $this->tickets->create($created);
            $this->intake->saveIntake($created->ticketId, $message->body);
            $sourceContext = $this->intake->context($source->ticketId);
            if ($sourceContext !== null) {
                $this->intake->saveContext($created->ticketId, $sourceContext);
            }
            $this->conversation->appendMessage($copied);
            $this->appendHistory(
                $created->ticketId,
                SupportHistoryEventType::Created,
                SupportHistoryVisibility::Public,
                ['status'=>$created->status->value],
                $now,
            );
            $this->conversation->addRelation($relation);
            $this->appendHistory(
                $source->ticketId,
                SupportHistoryEventType::SplitCreated,
                SupportHistoryVisibility::Public,
                ['new_ticket_id'=>$created->ticketId->value(),'message_id'=>$message->messageId->value()],
                $now,
            );
            $this->appendHistory(
                $created->ticketId,
                SupportHistoryEventType::SplitCreated,
                SupportHistoryVisibility::Public,
                ['source_ticket_id'=>$source->ticketId->value(),'source_message_id'=>$message->messageId->value()],
                $now,
            );
        });

        $this->safeNotify(fn () => $this->notifier->splitCreated($source, $created));
        return $created;
    }

    private function reopenForReply(SupportTicket $ticket, DateTimeImmutable $now): SupportTicket
    {
        $reopened = $this->tickets->changeStatus(
            $ticket->ticketId,
            SupportTicketStatus::Open,
            $ticket->sla->withResolved(null),
            null,
            $ticket->version,
            $now,
        );
        $this->appendHistory(
            $ticket->ticketId,
            SupportHistoryEventType::StatusChanged,
            SupportHistoryVisibility::Public,
            ['from'=>$ticket->status->value,'to'=>$reopened->status->value],
            $now,
        );
        return $reopened;
    }

    /**
     * @param array<string,scalar|null> $payload
     */
    private function appendHistory(
        EntityId $ticketId,
        SupportHistoryEventType $eventType,
        SupportHistoryVisibility $visibility,
        array $payload,
        DateTimeImmutable $now,
    ): void {
        $this->conversation->appendHistory(new SupportTicketHistoryEntry(
            SupportTicketHistoryEntry::generateId(),
            $ticketId,
            $this->gate->actorId(),
            $eventType,
            $visibility,
            $payload,
            $now,
        ));
    }

    private function safeNotify(\Closure $notify): void
    {
        try {
            $notify();
        } catch (Throwable) {
            // Ticket state is authoritative; notification delivery is an acceleration path.
        }
    }

    private static function dueAt(DateTimeImmutable $createdAt, ?int $minutes): ?DateTimeImmutable
    {
        return $minutes === null ? null : $createdAt->modify('+' . $minutes . ' minutes');
    }

    private static function utc(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
