<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Logs;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Admin\Operations\SystemLogReader;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use JsonException;
use Throwable;

final readonly class AdminLogExplorerService
{
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_AUDIT = 'audit';
    public const SOURCE_USER = 'user';

    public function __construct(
        private DatabaseConnection $database,
        private SystemLogReader $systemLogs,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    /** @return array<string,bool> */
    public function availableSources(EntityId $actor): array
    {
        $this->requireAcp($actor);

        return [
            self::SOURCE_SYSTEM => $this->allows($actor, 'system.logs.view'),
            self::SOURCE_AUDIT => $this->allows($actor, 'audit.view'),
            self::SOURCE_USER => $this->allows($actor, 'acp.manage'),
        ];
    }

    /**
     * @param list<string> $sources
     * @return list<AdminLogExplorerEntry>
     */
    public function search(
        EntityId $actor,
        string $query,
        array $sources,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        int $limit = 100,
    ): array {
        $available = $this->availableSources($actor);
        self::assertQuery($query);
        self::assertLimit($limit);
        self::assertDates($from, $to);

        $selected = [];
        foreach ($sources as $source) {
            if (!is_string($source) || !array_key_exists($source, $available)) {
                continue;
            }
            if ($available[$source]) {
                $selected[$source] = true;
            }
        }
        if ($selected === []) {
            foreach ($available as $source => $allowed) {
                if ($allowed) {
                    $selected[$source] = true;
                }
            }
        }

        $entries = [];
        if (isset($selected[self::SOURCE_SYSTEM])) {
            array_push($entries, ...$this->systemEntries($query, $from, $to));
        }
        if (isset($selected[self::SOURCE_AUDIT])) {
            array_push($entries, ...$this->auditEntries($query, $from, $to, min(250, max($limit, 100))));
        }
        if (isset($selected[self::SOURCE_USER])) {
            foreach ($this->userChanges($actor, $query, $from, $to, min(250, max($limit, 100)), false) as $change) {
                $entries[] = new AdminLogExplorerEntry(
                    self::SOURCE_USER,
                    $change->occurredAt,
                    $change->eventType,
                    implode(', ', $change->changedFields),
                    $change->actorUsername ?? $change->actorUserId,
                    $change->username . ' · ' . $change->userId,
                    array_filter([
                        'from_status' => $change->fromStatus,
                        'to_status' => $change->toStatus,
                        'reason' => $change->reasonCode,
                    ], static fn (mixed $value): bool => $value !== null),
                );
            }
        }

        usort(
            $entries,
            static fn (AdminLogExplorerEntry $left, AdminLogExplorerEntry $right): int =>
                $right->occurredAt <=> $left->occurredAt,
        );

        return array_slice($entries, 0, $limit);
    }

    /**
     * @return list<AdminUserChangeEntry>
     */
    public function userChanges(
        EntityId $actor,
        string $query,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        int $limit = 100,
        bool $requirePermission = true,
    ): array {
        if ($requirePermission) {
            $this->requireAcp($actor);
            $this->require($actor, 'acp.manage');
        }
        self::assertQuery($query);
        self::assertLimit($limit);
        self::assertDates($from, $to);

        [$where, $parameters] = $this->dateWhere('h.occurred_at_utc', $from, $to);
        $query = trim($query);
        if ($query !== '') {
            $parameters['search'] = self::like($query);
            $where[] = '(u.username LIKE :search ESCAPE \'=\' '
                . 'OR au.username LIKE :search ESCAPE \'=\' '
                . 'OR h.event_type LIKE :search ESCAPE \'=\' '
                . 'OR h.changed_fields_json LIKE :search ESCAPE \'=\' '
                . 'OR h.reason_code LIKE :search ESCAPE \'=\')';
        }

        $sql = 'SELECT h.user_id,u.username,h.event_type,h.changed_fields_json,h.occurred_at_utc,'
            . 'h.actor_user_id,au.username AS actor_username,h.from_status,h.to_status,h.reason_code '
            . 'FROM forwext_user_history h '
            . 'LEFT JOIN forwext_users u ON u.user_id=h.user_id '
            . 'LEFT JOIN forwext_users au ON au.user_id=h.actor_user_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY h.occurred_at_utc DESC,h.history_id DESC LIMIT ' . $limit;

        $rows = $this->database->fetchAll(new CompiledQuery($sql, $parameters));
        $result = [];
        foreach ($rows as $row) {
            $fields = self::fields((string) ($row['changed_fields_json'] ?? '[]'));
            $result[] = new AdminUserChangeEntry(
                (string) ($row['user_id'] ?? ''),
                is_string($row['username'] ?? null) && $row['username'] !== ''
                    ? $row['username']
                    : (string) ($row['user_id'] ?? ''),
                (string) ($row['event_type'] ?? ''),
                $fields,
                self::parseDatabaseTime((string) ($row['occurred_at_utc'] ?? '')),
                is_string($row['actor_user_id'] ?? null) ? $row['actor_user_id'] : null,
                is_string($row['actor_username'] ?? null) ? $row['actor_username'] : null,
                is_string($row['from_status'] ?? null) ? $row['from_status'] : null,
                is_string($row['to_status'] ?? null) ? $row['to_status'] : null,
                is_string($row['reason_code'] ?? null) ? $row['reason_code'] : null,
            );
        }

        return $result;
    }

    /** @return list<AdminLogExplorerEntry> */
    private function systemEntries(
        string $query,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
    ): array {
        $needle = mb_strtolower(trim($query), 'UTF-8');
        $entries = [];
        foreach ($this->systemLogs->tail(250) as $entry) {
            $time = self::parseFlexibleTime($entry->timestamp);
            if ($time === null || !self::inRange($time, $from, $to)) {
                continue;
            }

            $contextJson = self::json($entry->context);
            if ($needle !== '') {
                $haystack = mb_strtolower(
                    $entry->level . "\n" . $entry->message . "\n" . $contextJson,
                    'UTF-8',
                );
                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }

            $entries[] = new AdminLogExplorerEntry(
                self::SOURCE_SYSTEM,
                $time,
                strtoupper($entry->level),
                $entry->message,
                null,
                null,
                $entry->context,
            );
        }

        return $entries;
    }

    /** @return list<AdminLogExplorerEntry> */
    private function auditEntries(
        string $query,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        int $limit,
    ): array {
        [$where, $parameters] = $this->dateWhere('occurred_at_utc', $from, $to);
        $query = trim($query);
        if ($query !== '') {
            $parameters['search'] = self::like($query);
            $where[] = '(action LIKE :search ESCAPE \'=\' '
                . 'OR target_type LIKE :search ESCAPE \'=\' '
                . 'OR target_id LIKE :search ESCAPE \'=\' '
                . 'OR actor_user_id LIKE :search ESCAPE \'=\' '
                . 'OR request_id LIKE :search ESCAPE \'=\' '
                . 'OR reason_code LIKE :search ESCAPE \'=\')';
        }

        $sql = 'SELECT scope,actor_user_id,action,target_type,target_id,reason_code,request_id,'
            . 'before_json,after_json,occurred_at_utc FROM forwext_core_audit_events'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY occurred_at_utc DESC,audit_id DESC LIMIT ' . $limit;
        $rows = $this->database->fetchAll(new CompiledQuery($sql, $parameters));

        $result = [];
        foreach ($rows as $row) {
            $before = self::decodedObject((string) ($row['before_json'] ?? '{}'));
            $after = self::decodedObject((string) ($row['after_json'] ?? '{}'));
            $result[] = new AdminLogExplorerEntry(
                self::SOURCE_AUDIT,
                self::parseDatabaseTime((string) ($row['occurred_at_utc'] ?? '')),
                (string) ($row['action'] ?? ''),
                (string) ($row['scope'] ?? '') . ' · request ' . (string) ($row['request_id'] ?? ''),
                (string) ($row['actor_user_id'] ?? ''),
                (string) ($row['target_type'] ?? '') . ':' . (string) ($row['target_id'] ?? ''),
                [
                    'reason' => is_string($row['reason_code'] ?? null) ? $row['reason_code'] : null,
                    'before' => $before,
                    'after' => $after,
                ],
            );
        }

        return $result;
    }

    /**
     * @return array{0:list<string>,1:array<string,string>}
     */
    private function dateWhere(
        string $column,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
    ): array {
        $where = [];
        $parameters = [];
        if ($from !== null) {
            $where[] = $column . '>=:from';
            $parameters['from'] = self::format($from->setTime(0, 0, 0));
        }
        if ($to !== null) {
            $where[] = $column . '<=:to';
            $parameters['to'] = self::format($to->setTime(23, 59, 59, 999999));
        }

        return [$where, $parameters];
    }

    private function requireAcp(EntityId $actor): void
    {
        $this->require($actor, 'acp.access');
    }

    private function require(EntityId $actor, string $permission): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    private function allows(EntityId $actor, string $permission): bool
    {
        return $this->authorizer->allows($actor, PermissionKey::fromString($permission));
    }

    private static function assertQuery(string $query): void
    {
        if (strlen($query) > 120 || preg_match('//u', $query) !== 1) {
            throw new \InvalidArgumentException('Admin log search query is invalid.');
        }
    }

    private static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 250) {
            throw new \InvalidArgumentException('Admin log result limit is invalid.');
        }
    }

    private static function assertDates(?DateTimeImmutable $from, ?DateTimeImmutable $to): void
    {
        if ($from !== null && $to !== null && $from > $to) {
            throw new \InvalidArgumentException('Admin log date range is invalid.');
        }
    }

    private static function like(string $value): string
    {
        return '%' . strtr($value, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
    }

    /** @return list<string> */
    private static function fields(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn (mixed $value): bool => is_string($value)));
    }

    /** @return array<string|int,mixed> */
    private static function decodedObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return '{}';
        }
    }

    private static function parseFlexibleTime(string $value): ?DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function parseDatabaseTime(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new \RuntimeException('Stored admin log timestamp is invalid.');
        }

        return $time;
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function inRange(
        DateTimeImmutable $value,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
    ): bool {
        if ($from !== null && $value < $from->setTime(0, 0, 0)) {
            return false;
        }
        if ($to !== null && $value > $to->setTime(23, 59, 59, 999999)) {
            return false;
        }

        return true;
    }
}
