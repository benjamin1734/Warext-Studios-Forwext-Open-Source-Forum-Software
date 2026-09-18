<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Ticket;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;
use ValueError;

final readonly class DatabaseSupportTicketRepository implements SupportTicketRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function activeCategories(): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT category_key,label,description,default_priority,first_response_minutes,'
            . 'resolution_minutes,sort_order,active FROM forwext_support_categories '
            . 'WHERE active=1 ORDER BY sort_order,category_key',
        ));
        return array_map($this->hydrateCategory(...), $rows);
    }

    public function category(string $key): ?SupportCategory
    {
        SupportCategory::assertKey($key);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT category_key,label,description,default_priority,first_response_minutes,'
            . 'resolution_minutes,sort_order,active FROM forwext_support_categories '
            . 'WHERE category_key=:category_key LIMIT 1',
            ['category_key'=>$key],
        ));
        return $row === null ? null : $this->hydrateCategory($row);
    }

    public function saveCategory(SupportCategory $category): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_categories '
            . '(category_key,label,description,default_priority,first_response_minutes,'
            . 'resolution_minutes,sort_order,active,created_at_utc,updated_at_utc) '
            . 'VALUES (:category_key,:label,:description,:default_priority,:first_response_minutes,'
            . ':resolution_minutes,:sort_order,:active,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),'
            . 'default_priority=VALUES(default_priority),first_response_minutes=VALUES(first_response_minutes),'
            . 'resolution_minutes=VALUES(resolution_minutes),sort_order=VALUES(sort_order),'
            . 'active=VALUES(active),updated_at_utc=VALUES(updated_at_utc)',
            [
                'category_key'=>$category->key,
                'label'=>$category->label,
                'description'=>$category->description,
                'default_priority'=>$category->defaultPriority->value,
                'first_response_minutes'=>$category->firstResponseMinutes,
                'resolution_minutes'=>$category->resolutionMinutes,
                'sort_order'=>$category->sortOrder,
                'active'=>$category->active,
            ],
        ));
        if ($affected > 2) {
            throw new SupportTicketOperationException('Support category mutation affected an invalid row count.');
        }
    }

    public function create(SupportTicket $ticket): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_tickets '
            . '(ticket_id,category_key,requester_user_id,assigned_user_id,subject,priority,status,'
            . 'first_response_due_at_utc,resolution_due_at_utc,first_responded_at_utc,resolved_at_utc,'
            . 'closed_at_utc,created_at_utc,updated_at_utc,version) '
            . 'VALUES (:ticket_id,:category_key,:requester_user_id,:assigned_user_id,:subject,:priority,:status,'
            . ':first_response_due_at,:resolution_due_at,:first_responded_at,:resolved_at,:closed_at,'
            . ':created_at,:updated_at,:version)',
            [
                'ticket_id'=>$ticket->ticketId->value(),
                'category_key'=>$ticket->categoryKey,
                'requester_user_id'=>$ticket->requesterUserId?->value(),
                'assigned_user_id'=>$ticket->assignedUserId?->value(),
                'subject'=>$ticket->subject,
                'priority'=>$ticket->priority->value,
                'status'=>$ticket->status->value,
                'first_response_due_at'=>self::formatNullable($ticket->sla->firstResponseDueAt),
                'resolution_due_at'=>self::formatNullable($ticket->sla->resolutionDueAt),
                'first_responded_at'=>self::formatNullable($ticket->sla->firstRespondedAt),
                'resolved_at'=>self::formatNullable($ticket->sla->resolvedAt),
                'closed_at'=>self::formatNullable($ticket->closedAt),
                'created_at'=>self::format($ticket->createdAt),
                'updated_at'=>self::format($ticket->updatedAt),
                'version'=>$ticket->version,
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new SupportTicketOperationException('Support ticket was not persisted.');
        }
    }

    public function find(EntityId $ticketId): ?SupportTicket
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->ticketSelect() . ' WHERE ticket_id=:ticket_id LIMIT 1',
            ['ticket_id'=>$ticketId->value()],
        ));
        return $row === null ? null : $this->hydrateTicket($row);
    }

    public function forRequester(EntityId $requesterUserId, int $limit = 50): array
    {
        UserId::assert($requesterUserId);
        self::assertLimit($limit, 100);
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->ticketSelect() . ' WHERE requester_user_id=:requester_user_id '
            . 'ORDER BY updated_at_utc DESC,ticket_id DESC LIMIT ' . $limit,
            ['requester_user_id'=>$requesterUserId->value()],
        ));
        return array_map($this->hydrateTicket(...), $rows);
    }

    public function activeQueue(int $limit = 100): array
    {
        self::assertLimit($limit, 500);
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->ticketSelect() . " WHERE status IN ('open','in_progress','waiting_requester') "
            . "ORDER BY FIELD(priority,'urgent','high','normal','low'),"
            . 'resolution_due_at_utc IS NULL,resolution_due_at_utc,created_at_utc,ticket_id LIMIT ' . $limit,
        ));
        return array_map($this->hydrateTicket(...), $rows);
    }

    public function assign(
        EntityId $ticketId,
        ?EntityId $assignedUserId,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket {
        if ($assignedUserId !== null) {
            UserId::assert($assignedUserId);
        }
        $this->optimisticUpdate(
            'UPDATE forwext_support_tickets SET assigned_user_id=:assigned_user_id,'
            . 'updated_at_utc=:updated_at,version=version+1 '
            . 'WHERE ticket_id=:ticket_id AND version=:expected_version',
            [
                'assigned_user_id'=>$assignedUserId?->value(),
                'updated_at'=>self::format($now),
                'ticket_id'=>$ticketId->value(),
                'expected_version'=>$expectedVersion,
            ],
            $ticketId,
        );
        return $this->required($ticketId);
    }

    public function changePriority(
        EntityId $ticketId,
        SupportTicketPriority $priority,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket {
        $this->optimisticUpdate(
            'UPDATE forwext_support_tickets SET priority=:priority,updated_at_utc=:updated_at,'
            . 'version=version+1 WHERE ticket_id=:ticket_id AND version=:expected_version',
            [
                'priority'=>$priority->value,
                'updated_at'=>self::format($now),
                'ticket_id'=>$ticketId->value(),
                'expected_version'=>$expectedVersion,
            ],
            $ticketId,
        );
        return $this->required($ticketId);
    }

    public function changeStatus(
        EntityId $ticketId,
        SupportTicketStatus $status,
        SupportSlaMetadata $sla,
        ?DateTimeImmutable $closedAt,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket {
        $this->optimisticUpdate(
            'UPDATE forwext_support_tickets SET status=:status,resolved_at_utc=:resolved_at,'
            . 'closed_at_utc=:closed_at,updated_at_utc=:updated_at,version=version+1 '
            . 'WHERE ticket_id=:ticket_id AND version=:expected_version',
            [
                'status'=>$status->value,
                'resolved_at'=>self::formatNullable($sla->resolvedAt),
                'closed_at'=>self::formatNullable($closedAt),
                'updated_at'=>self::format($now),
                'ticket_id'=>$ticketId->value(),
                'expected_version'=>$expectedVersion,
            ],
            $ticketId,
        );
        return $this->required($ticketId);
    }

    public function markFirstResponse(
        EntityId $ticketId,
        DateTimeImmutable $firstRespondedAt,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): SupportTicket {
        $this->optimisticUpdate(
            'UPDATE forwext_support_tickets SET first_responded_at_utc=:first_responded_at,'
            . 'updated_at_utc=:updated_at,version=version+1 '
            . 'WHERE ticket_id=:ticket_id AND version=:expected_version AND first_responded_at_utc IS NULL',
            [
                'first_responded_at'=>self::format($firstRespondedAt),
                'updated_at'=>self::format($now),
                'ticket_id'=>$ticketId->value(),
                'expected_version'=>$expectedVersion,
            ],
            $ticketId,
        );
        return $this->required($ticketId);
    }

    /** @param array<string,string|int|bool|null> $parameters */
    private function optimisticUpdate(string $sql, array $parameters, EntityId $ticketId): void
    {
        $affected = $this->database->execute(new CompiledQuery($sql, $parameters, true));
        if ($affected === 1) {
            return;
        }
        if ($affected > 1) {
            throw new SupportTicketOperationException('Support ticket mutation affected multiple rows.');
        }
        if ($this->find($ticketId) === null) {
            throw new SupportTicketNotFoundException('Support ticket was not found.');
        }
        throw new SupportTicketOperationException('Support ticket changed concurrently; reload before retrying.');
    }

    private function required(EntityId $ticketId): SupportTicket
    {
        return $this->find($ticketId)
            ?? throw new SupportTicketNotFoundException('Support ticket was not found.');
    }

    private function ticketSelect(): string
    {
        return 'SELECT ticket_id,category_key,requester_user_id,assigned_user_id,subject,priority,status,'
            . 'first_response_due_at_utc,resolution_due_at_utc,first_responded_at_utc,resolved_at_utc,'
            . 'closed_at_utc,created_at_utc,updated_at_utc,version FROM forwext_support_tickets';
    }

    /** @param array<string,mixed> $row */
    private function hydrateCategory(array $row): SupportCategory
    {
        try {
            $priority = SupportTicketPriority::from((string) $row['default_priority']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored support category priority is invalid.', previous: $exception);
        }
        return new SupportCategory(
            (string) $row['category_key'],
            (string) $row['label'],
            (string) ($row['description'] ?? ''),
            $priority,
            $row['first_response_minutes'] === null ? null : (int) $row['first_response_minutes'],
            $row['resolution_minutes'] === null ? null : (int) $row['resolution_minutes'],
            (int) $row['sort_order'],
            (bool) $row['active'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateTicket(array $row): SupportTicket
    {
        try {
            $priority = SupportTicketPriority::from((string) $row['priority']);
            $status = SupportTicketStatus::from((string) $row['status']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored support ticket priority/status is invalid.', previous: $exception);
        }

        return new SupportTicket(
            EntityId::fromString((string) $row['ticket_id']),
            (string) $row['category_key'],
            $row['requester_user_id'] === null ? null : UserId::fromStored((string) $row['requester_user_id']),
            $row['assigned_user_id'] === null ? null : UserId::fromStored((string) $row['assigned_user_id']),
            (string) $row['subject'],
            $priority,
            $status,
            new SupportSlaMetadata(
                self::parseNullable($row['first_response_due_at_utc']),
                self::parseNullable($row['resolution_due_at_utc']),
                self::parseNullable($row['first_responded_at_utc']),
                self::parseNullable($row['resolved_at_utc']),
            ),
            self::parseNullable($row['closed_at_utc']),
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
            (int) $row['version'],
        );
    }

    private static function assertLimit(int $limit, int $max): void
    {
        if ($limit < 1 || $limit > $max) {
            throw new SupportTicketOperationException('Support ticket list limit is invalid.');
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function formatNullable(?DateTimeImmutable $value): ?string
    {
        return $value === null ? null : self::format($value);
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored support ticket timestamp is invalid.');
        }
        return $time;
    }

    private static function parseNullable(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? self::parse($value) : null;
    }
}
