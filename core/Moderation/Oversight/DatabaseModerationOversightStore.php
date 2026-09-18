<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Moderation\ModerationAuditEvent;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseModerationOversightStore implements ModerationOversightStore
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private ModerationOversightHasher $hasher = new ModerationOversightHasher(),
    ) {
    }

    public function append(ModerationAuditEvent $event): OversightEntry
    {
        if (!$this->database->inTransaction()) {
            throw new OversightOperationException('Oversight entries must be appended inside the mutation transaction.');
        }

        $stateRow = $this->database->fetchOne(new CompiledQuery(
            "SELECT last_sequence,last_hash FROM forwext_moderation_oversight_chain_state "
            . "WHERE chain_key='moderation' LIMIT 1 FOR UPDATE",
            [],
            true,
        ));
        if ($stateRow === null) {
            throw new OversightOperationException('Oversight chain state is unavailable.');
        }
        $state = new OversightChainState(
            (int) ($stateRow['last_sequence'] ?? -1),
            (string) ($stateRow['last_hash'] ?? ''),
        );

        $sequence = $state->lastSequence + 1;
        $payloadJson = $this->hasher->payloadJson($event);
        $payloadHash = $this->hasher->payloadHash($payloadJson);
        $chainHash = $this->hasher->chainHash($sequence, $state->lastHash, $payloadHash);

        $affected = $this->database->execute(new CompiledQuery(
            "INSERT INTO forwext_moderation_oversight_entries "
            . "(sequence_no,source_audit_id,actor_user_id,action,target_type,target_id,request_id,payload_json,"
            . "payload_hash,previous_hash,chain_hash,occurred_at_utc,appended_at_utc) "
            . "VALUES (:sequence_no,:source_audit_id,:actor_user_id,:action,:target_type,:target_id,:request_id,:payload_json,"
            . ":payload_hash,:previous_hash,:chain_hash,:occurred_at,UTC_TIMESTAMP(6))",
            [
                'sequence_no' => $sequence,
                'source_audit_id' => $event->auditId->value(),
                'actor_user_id' => $event->actorUserId->value(),
                'action' => $event->action->value,
                'target_type' => $event->targetType,
                'target_id' => $event->targetId,
                'request_id' => $event->requestId->value(),
                'payload_json' => $payloadJson,
                'payload_hash' => $payloadHash,
                'previous_hash' => $state->lastHash,
                'chain_hash' => $chainHash,
                'occurred_at' => self::format($event->occurredAt),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new OversightOperationException('Oversight entry was not persisted.');
        }

        $updated = $this->database->execute(new CompiledQuery(
            "UPDATE forwext_moderation_oversight_chain_state "
            . "SET last_sequence=:next_sequence,last_hash=:next_hash,updated_at_utc=UTC_TIMESTAMP(6) "
            . "WHERE chain_key='moderation' AND last_sequence=:previous_sequence AND last_hash=:previous_hash",
            [
                'next_sequence' => $sequence,
                'next_hash' => $chainHash,
                'previous_sequence' => $state->lastSequence,
                'previous_hash' => $state->lastHash,
            ],
            true,
        ));
        if ($updated !== 1) {
            throw new OversightOperationException('Oversight chain state lost concurrency ownership.');
        }

        return new OversightEntry(
            $sequence,
            $event->auditId,
            $event->actorUserId,
            $event->action->value,
            $event->targetType,
            $event->targetId,
            $event->requestId->value(),
            $payloadJson,
            $payloadHash,
            $state->lastHash,
            $chainHash,
            $event->occurredAt,
        );
    }

    public function findByAuditId(EntityId $auditId): ?OversightEntry
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectSql() . ' WHERE source_audit_id=:audit_id LIMIT 1',
            ['audit_id'=>$auditId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function pageAfter(int $sequence, int $limit = 500): array
    {
        if ($sequence < 0) {
            throw new InvalidArgumentException('Oversight page sequence cannot be negative.');
        }
        self::assertLimit($limit, 1000);
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->selectSql() . ' WHERE sequence_no>:sequence_no '
            . 'ORDER BY sequence_no ASC LIMIT ' . $limit,
            ['sequence_no'=>$sequence],
        ));
        return array_map($this->hydrate(...), $rows);
    }

    public function recent(int $limit = 100): array
    {
        self::assertLimit($limit, 500);
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->selectSql() . ' ORDER BY sequence_no DESC LIMIT ' . $limit,
        ));
        return array_map($this->hydrate(...), $rows);
    }

    public function chainState(): OversightChainState
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            "SELECT last_sequence,last_hash FROM forwext_moderation_oversight_chain_state "
            . "WHERE chain_key='moderation' LIMIT 1",
        ));
        if ($row === null) {
            throw new OversightOperationException('Oversight chain state is unavailable.');
        }
        return new OversightChainState(
            (int) ($row['last_sequence'] ?? -1),
            (string) ($row['last_hash'] ?? ''),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): OversightEntry
    {
        return new OversightEntry(
            (int) ($row['sequence_no'] ?? 0),
            EntityId::fromString((string) ($row['source_audit_id'] ?? '')),
            UserId::fromStored((string) ($row['actor_user_id'] ?? '')),
            (string) ($row['action'] ?? ''),
            (string) ($row['target_type'] ?? ''),
            (string) ($row['target_id'] ?? ''),
            (string) ($row['request_id'] ?? ''),
            (string) ($row['payload_json'] ?? ''),
            (string) ($row['payload_hash'] ?? ''),
            (string) ($row['previous_hash'] ?? ''),
            (string) ($row['chain_hash'] ?? ''),
            self::parse((string) ($row['occurred_at_utc'] ?? '')),
        );
    }

    private function selectSql(): string
    {
        return 'SELECT sequence_no,source_audit_id,actor_user_id,action,target_type,target_id,request_id,payload_json,'
            . 'payload_hash,previous_hash,chain_hash,occurred_at_utc FROM forwext_moderation_oversight_entries';
    }

    private static function assertLimit(int $limit, int $maximum): void
    {
        if ($limit < 1 || $limit > $maximum) {
            throw new InvalidArgumentException('Oversight list limit is outside the supported range.');
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
            throw new RuntimeException('Stored oversight timestamp is invalid.');
        }
        return $time;
    }
}
