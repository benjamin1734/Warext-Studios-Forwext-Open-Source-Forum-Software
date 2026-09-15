<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use JsonException;
use RuntimeException;

final readonly class DatabaseModerationAuditStore implements ModerationAuditStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function append(ModerationAuditEvent $event): void
    {
        if (!$this->database->inTransaction()) {
            throw new RuntimeException('Moderation audit events must be appended inside the mutation transaction.');
        }

        try {
            $before = json_encode($event->before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $after = json_encode($event->after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Moderation audit snapshot cannot be encoded.', previous: $exception);
        }

        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_moderation_audit_events` '
            . '(`audit_id`, `actor_user_id`, `action`, `target_type`, `target_id`, `forum_node_id`, '
            . '`reason_code`, `request_id`, `before_json`, `after_json`, `occurred_at_utc`) '
            . 'VALUES (:audit_id, :actor_user_id, :action, :target_type, :target_id, :forum_node_id, '
            . ':reason_code, :request_id, :before_json, :after_json, :occurred_at)',
            [
                'audit_id' => $event->auditId->value(),
                'actor_user_id' => $event->actorUserId->value(),
                'action' => $event->action->value,
                'target_type' => $event->targetType,
                'target_id' => $event->targetId,
                'forum_node_id' => $event->forumNodeId?->value(),
                'reason_code' => $event->reasonCode->value(),
                'request_id' => $event->requestId->value(),
                'before_json' => $before,
                'after_json' => $after,
                'occurred_at' => self::format($event->occurredAt),
            ],
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Moderation audit event was not persisted.');
        }
    }

    public function recentForTarget(string $targetType, string $targetId, int $limit = 100): array
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $targetType) !== 1
            || $targetId === '' || strlen($targetId) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $targetId) !== 1
            || $limit < 1 || $limit > 500
        ) {
            throw new RuntimeException('Moderation audit lookup parameters are invalid.');
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `audit_id`, `actor_user_id`, `action`, `target_type`, `target_id`, `forum_node_id`, '
            . '`reason_code`, `request_id`, `before_json`, `after_json`, `occurred_at_utc` '
            . 'FROM `forwext_moderation_audit_events` '
            . 'WHERE `target_type` = :target_type AND `target_id` = :target_id '
            . 'ORDER BY `occurred_at_utc` DESC, `audit_id` DESC LIMIT ' . $limit,
            ['target_type' => $targetType, 'target_id' => $targetId],
        ));

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ModerationAuditEvent
    {
        try {
            $before = json_decode((string) $row['before_json'], true, 32, JSON_THROW_ON_ERROR);
            $after = json_decode((string) $row['after_json'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored moderation audit snapshot is invalid.', previous: $exception);
        }
        if (!is_array($before) || !is_array($after)) {
            throw new RuntimeException('Stored moderation audit snapshot must be an object.');
        }

        return new ModerationAuditEvent(
            EntityId::fromString((string) $row['audit_id']),
            EntityId::fromString((string) $row['actor_user_id']),
            ModerationAuditAction::from((string) $row['action']),
            (string) $row['target_type'],
            (string) $row['target_id'],
            ($row['forum_node_id'] ?? null) === null ? null : EntityId::fromString((string) $row['forum_node_id']),
            ModerationReasonCode::fromString((string) $row['reason_code']),
            ModerationRequestId::fromString((string) $row['request_id']),
            $before,
            $after,
            self::parse((string) $row['occurred_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored moderation audit timestamp is invalid.');
        }
        return $time;
    }
}
