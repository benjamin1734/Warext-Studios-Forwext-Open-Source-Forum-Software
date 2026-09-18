<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use JsonException;
use RuntimeException;
use ValueError;

final readonly class DatabaseSupportConversationRepository implements SupportConversationRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function appendMessage(SupportConversationMessage $message): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_ticket_messages '
            . '(message_id,ticket_id,author_user_id,author_role,visibility,body,canned_response_key_snapshot,'
            . 'canned_response_title_snapshot,copied_from_message_id,created_at_utc) '
            . 'VALUES (:message_id,:ticket_id,:author_user_id,:author_role,:visibility,:body,:canned_key,'
            . ':canned_title,:copied_from_message_id,:created_at)',
            [
                'message_id'=>$message->messageId->value(),
                'ticket_id'=>$message->ticketId->value(),
                'author_user_id'=>$message->authorUserId?->value(),
                'author_role'=>$message->authorRole->value,
                'visibility'=>$message->visibility->value,
                'body'=>$message->body,
                'canned_key'=>$message->cannedResponseKeySnapshot,
                'canned_title'=>$message->cannedResponseTitleSnapshot,
                'copied_from_message_id'=>$message->copiedFromMessageId?->value(),
                'created_at'=>self::format($message->createdAt),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new SupportConversationOperationException('Support message was not persisted.');
        }
    }

    public function message(EntityId $messageId): ?SupportConversationMessage
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT message_id,ticket_id,author_user_id,author_role,visibility,body,canned_response_key_snapshot,'
            . 'canned_response_title_snapshot,copied_from_message_id,created_at_utc '
            . 'FROM forwext_support_ticket_messages WHERE message_id=:message_id LIMIT 1',
            ['message_id'=>$messageId->value()],
        ));
        return $row === null ? null : $this->hydrateMessage($row);
    }

    public function messages(EntityId $ticketId, bool $includeInternal, int $limit = 200): array
    {
        self::assertLimit($limit, 500);
        $sql = 'SELECT message_id,ticket_id,author_user_id,author_role,visibility,body,canned_response_key_snapshot,'
            . 'canned_response_title_snapshot,copied_from_message_id,created_at_utc '
            . 'FROM forwext_support_ticket_messages WHERE ticket_id=:ticket_id';
        if (!$includeInternal) {
            $sql .= " AND visibility='public'";
        }
        $sql .= ' ORDER BY created_at_utc ASC,message_id ASC LIMIT ' . $limit;
        $rows = $this->database->fetchAll(new CompiledQuery($sql, ['ticket_id'=>$ticketId->value()]));
        return array_map($this->hydrateMessage(...), $rows);
    }

    public function appendHistory(SupportTicketHistoryEntry $entry): void
    {
        try {
            $payload = json_encode(
                $entry->payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new SupportConversationOperationException('Support history payload cannot be encoded.', previous: $exception);
        }

        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_ticket_history '
            . '(history_id,ticket_id,actor_user_id,event_type,visibility,payload_json,created_at_utc) '
            . 'VALUES (:history_id,:ticket_id,:actor_user_id,:event_type,:visibility,:payload_json,:created_at)',
            [
                'history_id'=>$entry->historyId->value(),
                'ticket_id'=>$entry->ticketId->value(),
                'actor_user_id'=>$entry->actorUserId?->value(),
                'event_type'=>$entry->eventType->value,
                'visibility'=>$entry->visibility->value,
                'payload_json'=>$payload,
                'created_at'=>self::format($entry->createdAt),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new SupportConversationOperationException('Support history entry was not persisted.');
        }
    }

    public function history(EntityId $ticketId, bool $includeStaff, int $limit = 200): array
    {
        self::assertLimit($limit, 500);
        $sql = 'SELECT history_id,ticket_id,actor_user_id,event_type,visibility,payload_json,created_at_utc '
            . 'FROM forwext_support_ticket_history WHERE ticket_id=:ticket_id';
        if (!$includeStaff) {
            $sql .= " AND visibility='public'";
        }
        $sql .= ' ORDER BY created_at_utc ASC,history_id ASC LIMIT ' . $limit;
        $rows = $this->database->fetchAll(new CompiledQuery($sql, ['ticket_id'=>$ticketId->value()]));
        return array_map($this->hydrateHistory(...), $rows);
    }

    public function saveCannedResponse(SupportCannedResponse $response): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_canned_responses '
            . '(response_key,title,body,active,sort_order,created_at_utc,updated_at_utc) '
            . 'VALUES (:response_key,:title,:body,:active,:sort_order,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE title=VALUES(title),body=VALUES(body),active=VALUES(active),'
            . 'sort_order=VALUES(sort_order),updated_at_utc=VALUES(updated_at_utc)',
            [
                'response_key'=>$response->key,
                'title'=>$response->title,
                'body'=>$response->body,
                'active'=>$response->active,
                'sort_order'=>$response->sortOrder,
            ],
        ));
        if ($affected > 2) {
            throw new SupportConversationOperationException('Canned response mutation affected an invalid row count.');
        }
    }

    public function cannedResponse(string $key): ?SupportCannedResponse
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $key) !== 1) {
            throw new SupportConversationOperationException('Canned response key is invalid.');
        }
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT response_key,title,body,active,sort_order FROM forwext_support_canned_responses '
            . 'WHERE response_key=:response_key LIMIT 1',
            ['response_key'=>$key],
        ));
        return $row === null ? null : $this->hydrateCanned($row);
    }

    public function activeCannedResponses(): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT response_key,title,body,active,sort_order FROM forwext_support_canned_responses '
            . 'WHERE active=1 ORDER BY sort_order,response_key',
        ));
        return array_map($this->hydrateCanned(...), $rows);
    }

    public function escalation(EntityId $ticketId): ?SupportEscalationState
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT ticket_id,escalation_level,escalated_by_user_id,escalated_at_utc '
            . 'FROM forwext_support_ticket_escalations WHERE ticket_id=:ticket_id LIMIT 1',
            ['ticket_id'=>$ticketId->value()],
        ));
        return $row === null ? null : new SupportEscalationState(
            EntityId::fromString((string) $row['ticket_id']),
            (int) $row['escalation_level'],
            $row['escalated_by_user_id'] === null ? null : UserId::fromStored((string) $row['escalated_by_user_id']),
            self::parse((string) $row['escalated_at_utc']),
        );
    }

    public function setEscalation(SupportEscalationState $state): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_ticket_escalations '
            . '(ticket_id,escalation_level,escalated_by_user_id,escalated_at_utc) '
            . 'VALUES (:ticket_id,:level,:actor_id,:escalated_at) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'escalated_by_user_id=IF(VALUES(escalation_level)>escalation_level,VALUES(escalated_by_user_id),escalated_by_user_id),'
            . 'escalated_at_utc=IF(VALUES(escalation_level)>escalation_level,VALUES(escalated_at_utc),escalated_at_utc),'
            . 'escalation_level=GREATEST(escalation_level,VALUES(escalation_level))',
            [
                'ticket_id'=>$state->ticketId->value(),
                'level'=>$state->level,
                'actor_id'=>$state->escalatedByUserId?->value(),
                'escalated_at'=>self::format($state->escalatedAt),
            ],
        ));
        if ($affected < 1 || $affected > 2) {
            throw new SupportConversationOperationException('Support escalation state was not persisted.');
        }
    }

    public function addRelation(SupportTicketRelation $relation): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_support_ticket_relations '
            . '(relation_id,relation_type,source_ticket_id,target_ticket_id,created_by_user_id,created_at_utc) '
            . 'VALUES (:relation_id,:relation_type,:source_ticket_id,:target_ticket_id,:created_by_user_id,:created_at)',
            [
                'relation_id'=>$relation->relationId->value(),
                'relation_type'=>$relation->type->value,
                'source_ticket_id'=>$relation->sourceTicketId->value(),
                'target_ticket_id'=>$relation->targetTicketId->value(),
                'created_by_user_id'=>$relation->createdByUserId?->value(),
                'created_at'=>self::format($relation->createdAt),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new SupportConversationOperationException('Support ticket relation was not persisted.');
        }
    }

    public function relations(EntityId $ticketId): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT relation_id,relation_type,source_ticket_id,target_ticket_id,created_by_user_id,created_at_utc '
            . 'FROM forwext_support_ticket_relations '
            . 'WHERE source_ticket_id=:ticket_id OR target_ticket_id=:ticket_id '
            . 'ORDER BY created_at_utc,relation_id',
            ['ticket_id'=>$ticketId->value()],
        ));
        return array_map($this->hydrateRelation(...), $rows);
    }

    /** @param array<string,mixed> $row */
    private function hydrateMessage(array $row): SupportConversationMessage
    {
        try {
            $role = SupportMessageRole::from((string) $row['author_role']);
            $visibility = SupportMessageVisibility::from((string) $row['visibility']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored support message role/visibility is invalid.', previous: $exception);
        }
        return new SupportConversationMessage(
            EntityId::fromString((string) $row['message_id']),
            EntityId::fromString((string) $row['ticket_id']),
            $row['author_user_id'] === null ? null : UserId::fromStored((string) $row['author_user_id']),
            $role,
            $visibility,
            (string) $row['body'],
            $row['canned_response_key_snapshot'] === null ? null : (string) $row['canned_response_key_snapshot'],
            $row['canned_response_title_snapshot'] === null ? null : (string) $row['canned_response_title_snapshot'],
            $row['copied_from_message_id'] === null ? null : EntityId::fromString((string) $row['copied_from_message_id']),
            self::parse((string) $row['created_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateHistory(array $row): SupportTicketHistoryEntry
    {
        try {
            $event = SupportHistoryEventType::from((string) $row['event_type']);
            $visibility = SupportHistoryVisibility::from((string) $row['visibility']);
            $payload = json_decode((string) $row['payload_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (ValueError|JsonException $exception) {
            throw new RuntimeException('Stored support history entry is invalid.', previous: $exception);
        }
        if (!is_array($payload)) {
            throw new RuntimeException('Stored support history payload is invalid.');
        }
        return new SupportTicketHistoryEntry(
            EntityId::fromString((string) $row['history_id']),
            EntityId::fromString((string) $row['ticket_id']),
            $row['actor_user_id'] === null ? null : UserId::fromStored((string) $row['actor_user_id']),
            $event,
            $visibility,
            $payload,
            self::parse((string) $row['created_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateCanned(array $row): SupportCannedResponse
    {
        return new SupportCannedResponse(
            (string) $row['response_key'],
            (string) $row['title'],
            (string) $row['body'],
            (bool) $row['active'],
            (int) $row['sort_order'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateRelation(array $row): SupportTicketRelation
    {
        try {
            $type = SupportTicketRelationType::from((string) $row['relation_type']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored support ticket relation type is invalid.', previous: $exception);
        }
        return new SupportTicketRelation(
            EntityId::fromString((string) $row['relation_id']),
            $type,
            EntityId::fromString((string) $row['source_ticket_id']),
            EntityId::fromString((string) $row['target_ticket_id']),
            $row['created_by_user_id'] === null ? null : UserId::fromStored((string) $row['created_by_user_id']),
            self::parse((string) $row['created_at_utc']),
        );
    }

    private static function assertLimit(int $limit, int $max): void
    {
        if ($limit < 1 || $limit > $max) {
            throw new SupportConversationOperationException('Support conversation list limit is invalid.');
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored support conversation timestamp is invalid.');
        }
        return $time;
    }
}
