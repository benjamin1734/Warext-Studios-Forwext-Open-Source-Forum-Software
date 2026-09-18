<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use JsonException;
use RuntimeException;

final readonly class DatabaseAuditEventStore implements AuditEventStore
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private AuditRedactor $redactor = new SensitiveAuditRedactor(),
    ) {
    }

    public function append(AuditEvent $event): void
    {
        if (!$this->database->inTransaction()) {
            throw new RuntimeException('Core audit events must be appended inside the mutation transaction.');
        }

        try {
            $before = json_encode(
                $this->redactor->redact($event->before),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $after = json_encode(
                $this->redactor->redact($event->after),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Core audit snapshot cannot be encoded.', previous: $exception);
        }

        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_core_audit_events '
            . '(audit_id,scope,actor_user_id,action,target_type,target_id,forum_node_id,reason_code,request_id,'
            . 'before_json,after_json,occurred_at_utc) '
            . 'VALUES (:audit_id,:scope,:actor_user_id,:action,:target_type,:target_id,:forum_node_id,:reason_code,:request_id,'
            . ':before_json,:after_json,:occurred_at)',
            [
                'audit_id' => $event->auditId->value(),
                'scope' => $event->scope->value,
                'actor_user_id' => $event->actorUserId->value(),
                'action' => $event->action->value(),
                'target_type' => $event->targetType,
                'target_id' => $event->targetId,
                'forum_node_id' => $event->forumNodeId?->value(),
                'reason_code' => $event->reasonCode,
                'request_id' => $event->requestId->value(),
                'before_json' => $before,
                'after_json' => $after,
                'occurred_at' => self::format($event->occurredAt),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Core audit event was not persisted.');
        }
    }

    public function recent(int $limit = 100): array
    {
        self::assertLimit($limit);
        return $this->rows(
            ' ORDER BY occurred_at_utc DESC,audit_id DESC LIMIT ' . $limit,
            [],
        );
    }

    public function recentForTarget(
        string $targetType,
        string $targetId,
        int $limit = 100,
        ?AuditScope $scope = null,
    ): array {
        self::assertTarget($targetType, $targetId);
        self::assertLimit($limit);
        $where = ' WHERE target_type=:target_type AND target_id=:target_id';
        $parameters = ['target_type'=>$targetType,'target_id'=>$targetId];
        if ($scope !== null) {
            $where .= ' AND scope=:scope';
            $parameters['scope'] = $scope->value;
        }
        return $this->rows($where . ' ORDER BY occurred_at_utc DESC,audit_id DESC LIMIT ' . $limit, $parameters);
    }

    public function recentForActor(EntityId $actorUserId, int $limit = 100): array
    {
        UserId::assert($actorUserId);
        self::assertLimit($limit);
        return $this->rows(
            ' WHERE actor_user_id=:actor_user_id ORDER BY occurred_at_utc DESC,audit_id DESC LIMIT ' . $limit,
            ['actor_user_id'=>$actorUserId->value()],
        );
    }

    public function recentForRequest(AuditRequestId $requestId, int $limit = 100): array
    {
        self::assertLimit($limit);
        return $this->rows(
            ' WHERE request_id=:request_id ORDER BY occurred_at_utc DESC,audit_id DESC LIMIT ' . $limit,
            ['request_id'=>$requestId->value()],
        );
    }

    /** @param array<string,string|int|float|bool|null> $parameters @return list<AuditEvent> */
    private function rows(string $suffix, array $parameters): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery($this->selectSql() . $suffix, $parameters));
        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): AuditEvent
    {
        try {
            $before = json_decode((string) ($row['before_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
            $after = json_decode((string) ($row['after_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored core audit snapshot is invalid.', previous: $exception);
        }
        if (!is_array($before) || !is_array($after)) {
            throw new RuntimeException('Stored core audit snapshot must be an object or array.');
        }

        return new AuditEvent(
            EntityId::fromString((string) ($row['audit_id'] ?? '')),
            AuditScope::from((string) ($row['scope'] ?? '')),
            UserId::fromStored((string) ($row['actor_user_id'] ?? '')),
            AuditAction::fromString((string) ($row['action'] ?? '')),
            (string) ($row['target_type'] ?? ''),
            (string) ($row['target_id'] ?? ''),
            is_string($row['forum_node_id'] ?? null) ? EntityId::fromString($row['forum_node_id']) : null,
            is_string($row['reason_code'] ?? null) ? $row['reason_code'] : null,
            AuditRequestId::fromString((string) ($row['request_id'] ?? '')),
            $before,
            $after,
            self::parse((string) ($row['occurred_at_utc'] ?? '')),
        );
    }

    private function selectSql(): string
    {
        return 'SELECT audit_id,scope,actor_user_id,action,target_type,target_id,forum_node_id,reason_code,'
            . 'request_id,before_json,after_json,occurred_at_utc FROM forwext_core_audit_events';
    }

    private static function assertTarget(string $targetType, string $targetId): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $targetType) !== 1
            || $targetId === '' || strlen($targetId) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $targetId) !== 1
        ) {
            throw new RuntimeException('Core audit target lookup is invalid.');
        }
    }

    private static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new RuntimeException('Core audit list limit is invalid.');
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
            throw new RuntimeException('Stored core audit timestamp is invalid.');
        }
        return $time;
    }
}
