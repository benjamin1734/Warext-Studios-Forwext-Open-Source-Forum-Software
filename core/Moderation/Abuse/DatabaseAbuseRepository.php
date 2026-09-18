<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class DatabaseAbuseRepository implements AbuseRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function rules(AbuseEventType $eventType): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT rule_key,label,event_type,signal_key,hit_limit,window_seconds,action,active,priority '
            . 'FROM forwext_abuse_rules WHERE event_type=:event_type AND active=1 '
            . 'ORDER BY priority ASC, rule_key ASC',
            ['event_type' => $eventType->value],
        ));
        return array_map($this->hydrateRule(...), $rows);
    }

    public function allRules(): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT rule_key,label,event_type,signal_key,hit_limit,window_seconds,action,active,priority '
            . 'FROM forwext_abuse_rules ORDER BY priority ASC, rule_key ASC',
        ));
        return array_map($this->hydrateRule(...), $rows);
    }

    public function rule(string $key): ?AbuseRule
    {
        $key = strtolower(trim($key));
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Abuse rule key is invalid.');
        }
        $locking = $this->database->inTransaction();
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT rule_key,label,event_type,signal_key,hit_limit,window_seconds,action,active,priority '
            . 'FROM forwext_abuse_rules WHERE rule_key=:rule_key LIMIT 1'
            . ($locking ? ' FOR UPDATE' : ''),
            ['rule_key' => $key],
            $locking,
        ));
        return $row === null ? null : $this->hydrateRule($row);
    }

    public function saveRule(AbuseRule $rule, DateTimeImmutable $at): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_abuse_rules '
            . '(rule_key,label,event_type,signal_key,hit_limit,window_seconds,action,active,priority,created_at_utc,updated_at_utc) '
            . 'VALUES (:rule_key,:label,:event_type,:signal_key,:hit_limit,:window_seconds,:action,:active,:priority,:created_at,:updated_at) '
            . 'ON DUPLICATE KEY UPDATE label=VALUES(label),event_type=VALUES(event_type),signal_key=VALUES(signal_key),'
            . 'hit_limit=VALUES(hit_limit),window_seconds=VALUES(window_seconds),action=VALUES(action),'
            . 'active=VALUES(active),priority=VALUES(priority),updated_at_utc=VALUES(updated_at_utc)',
            [
                'rule_key' => $rule->key,
                'label' => $rule->label,
                'event_type' => $rule->eventType->value,
                'signal_key' => $rule->signal->value,
                'hit_limit' => $rule->limit,
                'window_seconds' => $rule->windowSeconds,
                'action' => $rule->action->value,
                'active' => $rule->active,
                'priority' => $rule->priority,
                'created_at' => self::format($at),
                'updated_at' => self::format($at),
            ],
        ));
    }

    public function consume(AbuseRule $rule, string $fingerprint, DateTimeImmutable $at): int
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new InvalidArgumentException('Abuse counter fingerprint is invalid.');
        }
        $bucketEpoch = intdiv($at->getTimestamp(), $rule->windowSeconds) * $rule->windowSeconds;
        $bucket = (new DateTimeImmutable('@' . $bucketEpoch))->setTimezone(new DateTimeZone('UTC'));

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use ($rule, $fingerprint, $bucket): int {
            $database->execute(new CompiledQuery(
                'INSERT INTO forwext_abuse_counters (rule_key,fingerprint,bucket_start_utc,hits) '
                . 'VALUES (:rule_key,:fingerprint,:bucket_start,1) '
                . 'ON DUPLICATE KEY UPDATE hits=hits+1',
                [
                    'rule_key' => $rule->key,
                    'fingerprint' => $fingerprint,
                    'bucket_start' => self::format($bucket),
                ],
                true,
            ));
            $hits = $database->fetchValue(new CompiledQuery(
                'SELECT hits FROM forwext_abuse_counters '
                . 'WHERE rule_key=:rule_key AND fingerprint=:fingerprint AND bucket_start_utc=:bucket_start LIMIT 1',
                [
                    'rule_key' => $rule->key,
                    'fingerprint' => $fingerprint,
                    'bucket_start' => self::format($bucket),
                ],
                true,
            ));
            if (!is_int($hits) && !is_string($hits)) {
                throw new AbuseOperationException('Abuse counter could not be read after increment.');
            }
            return (int) $hits;
        });
    }

    public function insertEvent(AbuseEvent $event): void
    {
        try {
            $rulesJson = json_encode($event->matchedRuleKeys, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new AbuseOperationException('Abuse event rules could not be encoded.', previous: $exception);
        }

        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_abuse_events '
            . '(event_id,event_type,decision,matched_rule_keys_json,actor_user_id,target_type,target_id,'
            . 'identity_fingerprint,ip_fingerprint,device_fingerprint,content_fingerprint,occurred_at_utc,'
            . 'resolved_at_utc,resolved_by_user_id,resolution) '
            . 'VALUES (:event_id,:event_type,:decision,:rules_json,:actor_user_id,:target_type,:target_id,'
            . ':identity_fingerprint,:ip_fingerprint,:device_fingerprint,:content_fingerprint,:occurred_at,'
            . 'NULL,NULL,NULL)',
            [
                'event_id' => $event->eventId->value(),
                'event_type' => $event->eventType->value,
                'decision' => $event->decision->value,
                'rules_json' => $rulesJson,
                'actor_user_id' => $event->actorUserId?->value(),
                'target_type' => $event->targetType,
                'target_id' => $event->targetId?->value(),
                'identity_fingerprint' => $event->identityFingerprint,
                'ip_fingerprint' => $event->ipFingerprint,
                'device_fingerprint' => $event->deviceFingerprint,
                'content_fingerprint' => $event->contentFingerprint,
                'occurred_at' => self::format($event->occurredAt),
            ],
        ));
        if ($affected !== 1) {
            throw new AbuseOperationException('Abuse event persistence did not insert exactly one row.');
        }
    }

    public function event(EntityId $eventId): ?AbuseEvent
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->eventSelect() . ' WHERE event_id=:event_id LIMIT 1',
            ['event_id' => $eventId->value()],
        ));
        return $row === null ? null : $this->hydrateEvent($row);
    }

    public function unresolved(int $limit = 100): array
    {
        self::assertLimit($limit);
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->eventSelect() . ' WHERE resolved_at_utc IS NULL '
            . 'ORDER BY occurred_at_utc DESC,event_id DESC LIMIT ' . $limit,
        ));
        return array_map($this->hydrateEvent(...), $rows);
    }

    public function unresolvedCount(): int
    {
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_abuse_events WHERE resolved_at_utc IS NULL',
        ));
    }

    public function resolve(EntityId $eventId, EntityId $actorUserId, string $resolution, DateTimeImmutable $at): void
    {
        UserId::assert($actorUserId);
        $resolution = trim($resolution);
        if ($resolution === '' || strlen($resolution) > 64) {
            throw new InvalidArgumentException('Abuse event resolution is invalid.');
        }
        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE forwext_abuse_events SET resolved_at_utc=:resolved_at,resolved_by_user_id=:resolved_by,'
            . 'resolution=:resolution WHERE event_id=:event_id AND resolved_at_utc IS NULL',
            [
                'resolved_at' => self::format($at),
                'resolved_by' => $actorUserId->value(),
                'resolution' => $resolution,
                'event_id' => $eventId->value(),
            ],
        ));
        if ($affected !== 1) {
            throw new AbuseOperationException('Abuse event resolution lost concurrency ownership or event was unavailable.');
        }
    }

    private function hydrateRule(array $row): AbuseRule
    {
        return new AbuseRule(
            (string) ($row['rule_key'] ?? ''),
            (string) ($row['label'] ?? ''),
            AbuseEventType::from((string) ($row['event_type'] ?? '')),
            AbuseSignal::from((string) ($row['signal_key'] ?? '')),
            (int) ($row['hit_limit'] ?? 0),
            (int) ($row['window_seconds'] ?? 0),
            AbuseAction::from((string) ($row['action'] ?? '')),
            (bool) ($row['active'] ?? false),
            (int) ($row['priority'] ?? 0),
        );
    }

    private function hydrateEvent(array $row): AbuseEvent
    {
        try {
            $decoded = json_decode((string) ($row['matched_rule_keys_json'] ?? '[]'), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored abuse event rule list is invalid.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Stored abuse event rule list is invalid.');
        }
        $keys = [];
        foreach ($decoded as $key) {
            if (!is_string($key)) {
                throw new RuntimeException('Stored abuse event rule key is invalid.');
            }
            $keys[] = $key;
        }

        return new AbuseEvent(
            EntityId::fromString((string) ($row['event_id'] ?? '')),
            AbuseEventType::from((string) ($row['event_type'] ?? '')),
            AbuseAction::from((string) ($row['decision'] ?? '')),
            $keys,
            is_string($row['actor_user_id'] ?? null) ? UserId::fromStored($row['actor_user_id']) : null,
            is_string($row['target_type'] ?? null) ? $row['target_type'] : null,
            is_string($row['target_id'] ?? null) ? EntityId::fromString($row['target_id']) : null,
            is_string($row['identity_fingerprint'] ?? null) ? $row['identity_fingerprint'] : null,
            is_string($row['ip_fingerprint'] ?? null) ? $row['ip_fingerprint'] : null,
            is_string($row['device_fingerprint'] ?? null) ? $row['device_fingerprint'] : null,
            is_string($row['content_fingerprint'] ?? null) ? $row['content_fingerprint'] : null,
            self::parse((string) ($row['occurred_at_utc'] ?? '')),
            is_string($row['resolved_at_utc'] ?? null) ? self::parse($row['resolved_at_utc']) : null,
            is_string($row['resolved_by_user_id'] ?? null) ? UserId::fromStored($row['resolved_by_user_id']) : null,
            is_string($row['resolution'] ?? null) ? $row['resolution'] : null,
        );
    }

    private function eventSelect(): string
    {
        return 'SELECT event_id,event_type,decision,matched_rule_keys_json,actor_user_id,target_type,target_id,'
            . 'identity_fingerprint,ip_fingerprint,device_fingerprint,content_fingerprint,occurred_at_utc,'
            . 'resolved_at_utc,resolved_by_user_id,resolution FROM forwext_abuse_events';
    }

    private static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Abuse event list limit must be between 1 and 500.');
        }
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored abuse timestamp is invalid.');
        }
        return $parsed;
    }
}
