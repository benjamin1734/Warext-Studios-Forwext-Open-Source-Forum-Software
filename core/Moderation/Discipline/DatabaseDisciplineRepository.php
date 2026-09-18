<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseDisciplineRepository implements DisciplineRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function warningDefinitions(bool $includeInactive = false): array
    {
        $sql = "SELECT definition_key,label,description,points,expiry_days,active,sort_order "
            . "FROM forwext_warning_definitions";
        if (!$includeInactive) {
            $sql .= " WHERE active = 1";
        }
        $sql .= " ORDER BY sort_order ASC, definition_key ASC";

        return array_map($this->hydrateDefinition(...), $this->database->fetchAll(new CompiledQuery($sql)));
    }

    public function warningDefinition(string $key): ?WarningDefinition
    {
        $key = strtolower(trim($key));
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Warning definition key is invalid.');
        }
        $row = $this->database->fetchOne(new CompiledQuery(
            "SELECT definition_key,label,description,points,expiry_days,active,sort_order "
            . "FROM forwext_warning_definitions WHERE definition_key = :definition_key LIMIT 1",
            ['definition_key' => $key],
        ));
        return $row === null ? null : $this->hydrateDefinition($row);
    }

    public function saveWarningDefinition(WarningDefinition $definition, DateTimeImmutable $at): void
    {
        $this->database->execute(new CompiledQuery(
            "INSERT INTO forwext_warning_definitions "
            . "(definition_key,label,description,points,expiry_days,active,sort_order,created_at_utc,updated_at_utc) "
            . "VALUES (:definition_key,:label,:description,:points,:expiry_days,:active,:sort_order,:created_at,:updated_at) "
            . "ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),points=VALUES(points),"
            . "expiry_days=VALUES(expiry_days),active=VALUES(active),sort_order=VALUES(sort_order),updated_at_utc=VALUES(updated_at_utc)",
            [
                'definition_key' => $definition->key,
                'label' => $definition->label,
                'description' => $definition->description,
                'points' => $definition->points,
                'expiry_days' => $definition->expiryDays,
                'active' => $definition->active,
                'sort_order' => $definition->sortOrder,
                'created_at' => self::format($at),
                'updated_at' => self::format($at),
            ],
        ));
    }

    public function insertAction(DisciplineAction $action): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            "INSERT INTO forwext_discipline_actions "
            . "(action_id,user_id,actor_user_id,action_type,warning_definition_key,reason_code,reason_text,"
            . "points,appealable,starts_at_utc,expires_at_utc,revoked_at_utc,revoked_by_user_id,revoke_reason,created_at_utc) "
            . "VALUES (:action_id,:user_id,:actor_user_id,:action_type,:warning_definition_key,:reason_code,:reason_text,"
            . ":points,:appealable,:starts_at,:expires_at,NULL,NULL,NULL,:created_at)",
            [
                'action_id' => $action->actionId->value(),
                'user_id' => $action->userId->value(),
                'actor_user_id' => $action->actorUserId?->value(),
                'action_type' => $action->type->value,
                'warning_definition_key' => $action->warningDefinitionKey,
                'reason_code' => $action->reasonCode->value(),
                'reason_text' => $action->reasonText,
                'points' => $action->points,
                'appealable' => $action->appealable,
                'starts_at' => self::format($action->startsAt),
                'expires_at' => $action->expiresAt === null ? null : self::format($action->expiresAt),
                'created_at' => self::format($action->startsAt),
            ],
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Discipline action persistence did not insert exactly one row.');
        }

        foreach ($action->restrictions as $restriction) {
            $affected = $this->database->execute(new CompiledQuery(
                "INSERT INTO forwext_discipline_action_restrictions (action_id,restriction_key) "
                . "VALUES (:action_id,:restriction_key)",
                ['action_id' => $action->actionId->value(), 'restriction_key' => $restriction->value],
            ));
            if ($affected !== 1) {
                throw new RuntimeException('Discipline restriction persistence did not insert exactly one row.');
            }
        }
    }

    public function action(EntityId $actionId): ?DisciplineAction
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->actionSelect() . " WHERE action_id = :action_id LIMIT 1",
            ['action_id' => $actionId->value()],
        ));
        if ($row === null) {
            return null;
        }
        return $this->hydrateAction($row, $this->restrictionMap([$actionId->value()]));
    }

    public function revoke(
        EntityId $actionId,
        EntityId $actorUserId,
        string $reason,
        DateTimeImmutable $at,
    ): DisciplineAction {
        UserId::assert($actorUserId);
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 1000) {
            throw new InvalidArgumentException('Discipline revoke reason must contain 1-1000 bytes.');
        }

        $row = $this->database->fetchOne(new CompiledQuery(
            $this->actionSelect() . " WHERE action_id = :action_id LIMIT 1 FOR UPDATE",
            ['action_id' => $actionId->value()],
            true,
        ));
        if ($row === null) {
            throw new DisciplineOperationException('Discipline action was not found.');
        }
        if (($row['revoked_at_utc'] ?? null) !== null) {
            throw new DisciplineOperationException('Discipline action is already revoked.');
        }

        $affected = $this->database->execute(new CompiledQuery(
            "UPDATE forwext_discipline_actions SET revoked_at_utc=:revoked_at,"
            . "revoked_by_user_id=:revoked_by,revoke_reason=:revoke_reason "
            . "WHERE action_id=:action_id AND revoked_at_utc IS NULL",
            [
                'revoked_at' => self::format($at),
                'revoked_by' => $actorUserId->value(),
                'revoke_reason' => $reason,
                'action_id' => $actionId->value(),
            ],
            true,
        ));
        if ($affected !== 1) {
            throw new DisciplineOperationException('Discipline action revocation lost concurrency ownership.');
        }

        $row['revoked_at_utc'] = self::format($at);
        $row['revoked_by_user_id'] = $actorUserId->value();
        $row['revoke_reason'] = $reason;
        return $this->hydrateAction($row, $this->restrictionMap([$actionId->value()]));
    }

    public function forUser(EntityId $userId, int $limit = 100): array
    {
        UserId::assert($userId);
        self::assertLimit($limit);
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->actionSelect() . " WHERE user_id=:user_id "
            . "ORDER BY starts_at_utc DESC, action_id DESC LIMIT " . $limit,
            ['user_id' => $userId->value()],
        ));
        return $this->hydrateMany($rows);
    }

    public function recent(array $types, int $limit = 50): array
    {
        self::assertLimit($limit);
        [$in, $parameters] = self::typeFilter($types);
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->actionSelect() . " WHERE action_type IN (" . $in . ") "
            . "ORDER BY starts_at_utc DESC, action_id DESC LIMIT " . $limit,
            $parameters,
        ));
        return $this->hydrateMany($rows);
    }

    public function activeCount(array $types, DateTimeImmutable $at): int
    {
        [$in, $parameters] = self::typeFilter($types);
        $parameters['at'] = self::format($at);
        return (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_discipline_actions "
            . "WHERE action_type IN (" . $in . ") AND revoked_at_utc IS NULL "
            . "AND starts_at_utc <= :at AND (expires_at_utc IS NULL OR expires_at_utc > :at)",
            $parameters,
        ));
    }

    public function activePoints(EntityId $userId, DateTimeImmutable $at): int
    {
        UserId::assert($userId);
        return (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COALESCE(SUM(points),0) FROM forwext_discipline_actions "
            . "WHERE user_id=:user_id AND action_type='warning' AND revoked_at_utc IS NULL "
            . "AND starts_at_utc <= :at AND (expires_at_utc IS NULL OR expires_at_utc > :at)",
            ['user_id' => $userId->value(), 'at' => self::format($at)],
        ));
    }

    /** @param list<array<string,mixed>> $rows @return list<DisciplineAction> */
    private function hydrateMany(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_string($row['action_id'] ?? null)) {
                $ids[] = $row['action_id'];
            }
        }
        $restrictionMap = $this->restrictionMap($ids);
        return array_map(fn (array $row): DisciplineAction => $this->hydrateAction($row, $restrictionMap), $rows);
    }

    /** @param list<string> $actionIds @return array<string,list<DisciplineRestrictionKey>> */
    private function restrictionMap(array $actionIds): array
    {
        if ($actionIds === []) {
            return [];
        }
        $parameters = [];
        $placeholders = [];
        foreach (array_values(array_unique($actionIds)) as $index => $actionId) {
            $name = 'action_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $actionId;
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            "SELECT action_id,restriction_key FROM forwext_discipline_action_restrictions "
            . "WHERE action_id IN (" . implode(',', $placeholders) . ") ORDER BY action_id,restriction_key",
            $parameters,
        ));
        $map = [];
        foreach ($rows as $row) {
            $actionId = (string) ($row['action_id'] ?? '');
            $map[$actionId][] = DisciplineRestrictionKey::from((string) ($row['restriction_key'] ?? ''));
        }
        return $map;
    }

    /** @param array<string,list<DisciplineRestrictionKey>> $restrictionMap */
    private function hydrateAction(array $row, array $restrictionMap): DisciplineAction
    {
        $id = (string) ($row['action_id'] ?? '');
        return new DisciplineAction(
            EntityId::fromString($id),
            UserId::fromStored((string) ($row['user_id'] ?? '')),
            is_string($row['actor_user_id'] ?? null) ? UserId::fromStored($row['actor_user_id']) : null,
            DisciplineActionType::from((string) ($row['action_type'] ?? '')),
            ModerationReasonCode::fromString((string) ($row['reason_code'] ?? '')),
            (string) ($row['reason_text'] ?? ''),
            (int) ($row['points'] ?? 0),
            is_string($row['warning_definition_key'] ?? null) ? $row['warning_definition_key'] : null,
            $restrictionMap[$id] ?? [],
            (bool) ($row['appealable'] ?? false),
            self::parse((string) ($row['starts_at_utc'] ?? '')),
            is_string($row['expires_at_utc'] ?? null) ? self::parse($row['expires_at_utc']) : null,
            is_string($row['revoked_at_utc'] ?? null) ? self::parse($row['revoked_at_utc']) : null,
            is_string($row['revoked_by_user_id'] ?? null) ? UserId::fromStored($row['revoked_by_user_id']) : null,
            is_string($row['revoke_reason'] ?? null) ? $row['revoke_reason'] : null,
        );
    }

    private function hydrateDefinition(array $row): WarningDefinition
    {
        return new WarningDefinition(
            (string) ($row['definition_key'] ?? ''),
            (string) ($row['label'] ?? ''),
            (string) ($row['description'] ?? ''),
            (int) ($row['points'] ?? 0),
            ($row['expiry_days'] ?? null) === null ? null : (int) $row['expiry_days'],
            (bool) ($row['active'] ?? false),
            (int) ($row['sort_order'] ?? 0),
        );
    }

    private function actionSelect(): string
    {
        return "SELECT action_id,user_id,actor_user_id,action_type,warning_definition_key,reason_code,reason_text,"
            . "points,appealable,starts_at_utc,expires_at_utc,revoked_at_utc,revoked_by_user_id,revoke_reason "
            . "FROM forwext_discipline_actions";
    }

    /** @param list<DisciplineActionType> $types @return array{0:string,1:array<string,string>} */
    private static function typeFilter(array $types): array
    {
        if ($types === []) {
            throw new InvalidArgumentException('Discipline action type filter cannot be empty.');
        }
        $parameters = [];
        $placeholders = [];
        foreach ($types as $index => $type) {
            if (!$type instanceof DisciplineActionType) {
                throw new InvalidArgumentException('Discipline action type filter is invalid.');
            }
            $name = 'type_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $type->value;
        }
        return [implode(',', $placeholders), $parameters];
    }

    private static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Discipline list limit must be between 1 and 500.');
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
            throw new RuntimeException('Stored discipline timestamp is invalid.');
        }
        return $time;
    }
}
