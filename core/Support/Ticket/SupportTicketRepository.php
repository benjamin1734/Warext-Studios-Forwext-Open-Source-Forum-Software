<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Ticket;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface SupportTicketRepository
{
    /** @return list<SupportCategory> */
    public function activeCategories(): array;
    public function category(string $key): ?SupportCategory;
    public function saveCategory(SupportCategory $category): void;
    public function create(SupportTicket $ticket): void;
    public function find(EntityId $ticketId): ?SupportTicket;

    /** @return list<SupportTicket> */
    public function forRequester(EntityId $requesterUserId, int $limit = 50): array;

    /** @return list<SupportTicket> */
    public function activeQueue(int $limit = 100): array;

    public function assign(
        EntityId $ticketId,
        ?EntityId $assignedUserId,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket;

    public function changePriority(
        EntityId $ticketId,
        SupportTicketPriority $priority,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket;

    public function changeStatus(
        EntityId $ticketId,
        SupportTicketStatus $status,
        SupportSlaMetadata $sla,
        ?DateTimeImmutable $closedAt,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket;

    public function markFirstResponse(
        EntityId $ticketId,
        DateTimeImmutable $firstRespondedAt,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket;
}
