<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Ticket;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class SupportTicketService
{
    public const CREATE_PERMISSION = 'support.ticket.create';
    public const VIEW_OWN_PERMISSION = 'support.ticket.view_own';
    public const VIEW_ALL_PERMISSION = 'support.ticket.view_all';
    public const MANAGE_PERMISSION = 'support.ticket.manage';
    public const ASSIGN_PERMISSION = 'support.ticket.assign';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private SupportTicketRepository $tickets,
        private PermissionGate $gate,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    /** @return list<SupportCategory> */
    public function categories(): array
    {
        if (!$this->gate->allows(PermissionKey::fromString(self::CREATE_PERMISSION))
            && !$this->gate->allows(PermissionKey::fromString(self::VIEW_ALL_PERMISSION))
        ) {
            $this->gate->require(PermissionKey::fromString(self::CREATE_PERMISSION));
        }
        return $this->tickets->activeCategories();
    }

    public function saveCategory(SupportCategory $category): void
    {
        $this->gate->require(PermissionKey::fromString(self::MANAGE_PERMISSION));
        $this->tickets->saveCategory($category);
    }

    public function create(
        string $categoryKey,
        string $subject,
        ?DateTimeImmutable $now = null,
    ): SupportTicket {
        $this->gate->require(PermissionKey::fromString(self::CREATE_PERMISSION));
        $category = $this->tickets->category(strtolower(trim($categoryKey)));
        if ($category === null || !$category->active) {
            throw new InvalidArgumentException('Support category is unavailable.');
        }

        $subject = trim($subject);
        $now = self::utc($now);
        $ticket = new SupportTicket(
            SupportTicket::generateId(),
            $category->key,
            $this->gate->actorId(),
            null,
            $subject,
            $category->defaultPriority,
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

        if ($this->database->inTransaction()) {
            $this->tickets->create($ticket);
        } else {
            $this->database->transaction(function () use ($ticket): void {
                $this->tickets->create($ticket);
            });
        }
        return $ticket;
    }

    /** @return list<SupportTicket> */
    public function own(int $limit = 50): array
    {
        $this->gate->require(PermissionKey::fromString(self::VIEW_OWN_PERMISSION));
        return $this->tickets->forRequester($this->gate->actorId(), $limit);
    }

    public function ticket(EntityId $ticketId): SupportTicket
    {
        $ticket = $this->tickets->find($ticketId)
            ?? throw new SupportTicketNotFoundException('Support ticket was not found.');

        if ($ticket->isRequester($this->gate->actorId())) {
            $this->gate->require(PermissionKey::fromString(self::VIEW_OWN_PERMISSION));
            return $ticket;
        }

        $this->gate->require(PermissionKey::fromString(self::VIEW_ALL_PERMISSION));
        return $ticket;
    }

    /** @return list<SupportTicket> */
    public function activeQueue(int $limit = 100): array
    {
        $this->gate->require(PermissionKey::fromString(self::VIEW_ALL_PERMISSION));
        return $this->tickets->activeQueue($limit);
    }

    public function assign(
        EntityId $ticketId,
        ?EntityId $assignedUserId,
        ?DateTimeImmutable $now = null,
    ): SupportTicket {
        $this->gate->require(PermissionKey::fromString(self::ASSIGN_PERMISSION));
        if ($assignedUserId !== null
            && !$this->authorizer->allows(
                $assignedUserId,
                PermissionKey::fromString(self::VIEW_ALL_PERMISSION),
            )
        ) {
            throw new InvalidArgumentException('Support assignee does not have support ticket staff access.');
        }

        return $this->atomic(function () use ($ticketId, $assignedUserId, $now): SupportTicket {
            $current = $this->required($ticketId);
            if (!$current->status->isActive()) {
                throw new SupportTicketOperationException('Resolved/closed support tickets cannot be reassigned.');
            }
            if ($current->assignedUserId?->value() === $assignedUserId?->value()) {
                return $current;
            }

            return $this->tickets->assign(
                $ticketId,
                $assignedUserId,
                $current->version,
                self::utc($now),
            );
        });
    }

    public function changePriority(
        EntityId $ticketId,
        SupportTicketPriority $priority,
        ?DateTimeImmutable $now = null,
    ): SupportTicket {
        $this->gate->require(PermissionKey::fromString(self::MANAGE_PERMISSION));

        return $this->atomic(function () use ($ticketId, $priority, $now): SupportTicket {
            $current = $this->required($ticketId);
            if (!$current->status->isActive()) {
                throw new SupportTicketOperationException('Resolved/closed support tickets cannot change priority.');
            }
            if ($current->priority === $priority) {
                return $current;
            }

            return $this->tickets->changePriority(
                $ticketId,
                $priority,
                $current->version,
                self::utc($now),
            );
        });
    }

    public function changeStatus(
        EntityId $ticketId,
        SupportTicketStatus $status,
        ?DateTimeImmutable $now = null,
    ): SupportTicket {
        $this->gate->require(PermissionKey::fromString(self::MANAGE_PERMISSION));

        return $this->atomic(function () use ($ticketId, $status, $now): SupportTicket {
            $current = $this->required($ticketId);
            if ($current->status === $status) {
                return $current;
            }
            if (!$current->status->canTransitionTo($status)) {
                throw new SupportTicketOperationException('Requested support ticket lifecycle transition is not allowed.');
            }

            $at = self::utc($now);
            $resolvedAt = $current->sla->resolvedAt;
            $closedAt = $current->closedAt;

            if ($status === SupportTicketStatus::Resolved || $status === SupportTicketStatus::Closed) {
                $resolvedAt ??= $at;
            } else {
                $resolvedAt = null;
            }
            if ($status === SupportTicketStatus::Closed) {
                $closedAt ??= $at;
            } else {
                $closedAt = null;
            }

            return $this->tickets->changeStatus(
                $ticketId,
                $status,
                $current->sla->withResolved($resolvedAt),
                $closedAt,
                $current->version,
                $at,
            );
        });
    }

    public function markFirstResponse(
        EntityId $ticketId,
        ?DateTimeImmutable $now = null,
    ): SupportTicket {
        $this->gate->require(PermissionKey::fromString(self::MANAGE_PERMISSION));

        return $this->atomic(function () use ($ticketId, $now): SupportTicket {
            $current = $this->required($ticketId);
            if ($current->sla->firstRespondedAt !== null) {
                return $current;
            }
            if ($current->status === SupportTicketStatus::Closed) {
                throw new SupportTicketOperationException('Closed support tickets cannot receive a first response marker.');
            }
            $at = self::utc($now);
            return $this->tickets->markFirstResponse($ticketId, $at, $current->version, $at);
        });
    }

    private function required(EntityId $ticketId): SupportTicket
    {
        return $this->tickets->find($ticketId)
            ?? throw new SupportTicketNotFoundException('Support ticket was not found.');
    }

    private function atomic(\Closure $callback): mixed
    {
        return $this->database->inTransaction()
            ? $callback()
            : $this->database->transaction(static fn () => $callback());
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
