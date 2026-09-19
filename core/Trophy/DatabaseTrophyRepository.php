<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final readonly class DatabaseTrophyRepository implements TrophyRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function findDefinition(EntityId $trophyId): ?TrophyDefinition
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_trophies WHERE trophy_id=:id LIMIT 1',
            ['id'=>$trophyId->value()],
        ));
        return $row === null ? null : $this->hydrateDefinition($row);
    }

    public function definitions(bool $activeOnly = false, int $limit = 500): array
    {
        self::limit($limit);
        $where = $activeOnly ? ' WHERE active=1' : '';
        return array_map(
            $this->hydrateDefinition(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT * FROM forwext_trophies' . $where . ' ORDER BY priority DESC,name,trophy_id LIMIT ' . $limit,
            )),
        );
    }

    public function saveDefinition(TrophyDefinition $definition): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_trophies '
            . '(trophy_id,trophy_key,name,description,kind,active,priority,icon_path,banner_path,rule_type,threshold,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:key,:name,:description,:kind,:active,:priority,:icon,:banner,:rule_type,:threshold,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE trophy_key=VALUES(trophy_key),name=VALUES(name),description=VALUES(description),'
            . 'kind=VALUES(kind),active=VALUES(active),priority=VALUES(priority),icon_path=VALUES(icon_path),banner_path=VALUES(banner_path),'
            . 'rule_type=VALUES(rule_type),threshold=VALUES(threshold),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$definition->trophyId->value(),'key'=>$definition->key,'name'=>$definition->name,
                'description'=>$definition->description,'kind'=>$definition->kind->value,'active'=>$definition->active ? 1 : 0,
                'priority'=>$definition->priority,'icon'=>$definition->iconPath,'banner'=>$definition->bannerPath,
                'rule_type'=>$definition->ruleType->value,'threshold'=>$definition->threshold,
                'created'=>self::format($definition->createdAt),'updated'=>self::format($definition->updatedAt),
            ],
        ));
    }

    public function grantForUser(EntityId $trophyId, EntityId $userId): ?TrophyGrant
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_user_trophies WHERE trophy_id=:trophy_id AND user_id=:user_id LIMIT 1',
            ['trophy_id'=>$trophyId->value(),'user_id'=>$userId->value()],
        ));
        return $row === null ? null : $this->hydrateGrant($row);
    }

    public function activeForUser(EntityId $userId, int $limit = 100): array
    {
        self::limit($limit);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT t.*,g.grant_id,g.user_id,g.source,g.awarded_by_user_id,g.awarded_at_utc,'
            . 'g.revoked_by_user_id,g.revoked_at_utc,g.reason '
            . 'FROM forwext_user_trophies g INNER JOIN forwext_trophies t ON t.trophy_id=g.trophy_id '
            . 'WHERE g.user_id=:user_id AND g.revoked_at_utc IS NULL AND t.active=1 '
            . 'ORDER BY t.priority DESC,g.awarded_at_utc DESC,t.trophy_id LIMIT ' . $limit,
            ['user_id'=>$userId->value()],
        ));
        $items=[];
        foreach($rows as $row){
            $items[]=['definition'=>$this->hydrateDefinition($row),'grant'=>$this->hydrateGrant($row)];
        }
        return $items;
    }

    public function historyForUser(EntityId $userId, int $limit = 100, int $offset = 0): array
    {
        self::limit($limit);
        if ($offset < 0 || $offset > 1_000_000) throw new InvalidArgumentException('Trophy history offset is invalid.');
        return array_map(
            $this->hydrateHistory(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT history_id,grant_id,trophy_id,user_id,action,source,actor_user_id,reason,occurred_at_utc '
                . 'FROM forwext_trophy_history WHERE user_id=:user_id ORDER BY history_id DESC LIMIT '
                . $limit . ' OFFSET ' . $offset,
                ['user_id'=>$userId->value()],
            )),
        );
    }

    public function saveGrant(TrophyGrant $grant, TrophyHistoryEntry $history): void
    {
        $this->database->transaction(function () use ($grant,$history): void {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_user_trophies '
                . '(grant_id,trophy_id,user_id,source,awarded_by_user_id,awarded_at_utc,revoked_by_user_id,revoked_at_utc,reason) '
                . 'VALUES (:grant_id,:trophy_id,:user_id,:source,:awarded_by,:awarded_at,:revoked_by,:revoked_at,:reason) '
                . 'ON DUPLICATE KEY UPDATE source=VALUES(source),awarded_by_user_id=VALUES(awarded_by_user_id),'
                . 'awarded_at_utc=VALUES(awarded_at_utc),revoked_by_user_id=VALUES(revoked_by_user_id),'
                . 'revoked_at_utc=VALUES(revoked_at_utc),reason=VALUES(reason)',
                [
                    'grant_id'=>$grant->grantId->value(),'trophy_id'=>$grant->trophyId->value(),'user_id'=>$grant->userId->value(),
                    'source'=>$grant->source,'awarded_by'=>$grant->awardedByUserId?->value(),'awarded_at'=>self::format($grant->awardedAt),
                    'revoked_by'=>$grant->revokedByUserId?->value(),'revoked_at'=>$grant->revokedAt===null?null:self::format($grant->revokedAt),
                    'reason'=>$grant->reason,
                ],
            ));
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_trophy_history '
                . '(grant_id,trophy_id,user_id,action,source,actor_user_id,reason,occurred_at_utc) '
                . 'VALUES (:grant_id,:trophy_id,:user_id,:action,:source,:actor,:reason,:occurred)',
                [
                    'grant_id'=>$history->grantId->value(),'trophy_id'=>$history->trophyId->value(),'user_id'=>$history->userId->value(),
                    'action'=>$history->action->value,'source'=>$history->source,'actor'=>$history->actorUserId?->value(),
                    'reason'=>$history->reason,'occurred'=>self::format($history->occurredAt),
                ],
            ));
        });
    }

    public function evaluationCursor(): ?EntityId
    {
        $value=$this->database->fetchValue(new CompiledQuery(
            "SELECT cursor_user_id FROM forwext_trophy_runtime_state WHERE state_key='rule_evaluation' LIMIT 1",
        ));
        return is_string($value) && $value!=='' ? UserId::fromStored($value) : null;
    }

    public function setEvaluationCursor(?EntityId $userId): void
    {
        $this->database->execute(new CompiledQuery(
            "INSERT INTO forwext_trophy_runtime_state(state_key,cursor_user_id,updated_at_utc) "
            . "VALUES ('rule_evaluation',:cursor,UTC_TIMESTAMP(6)) "
            . "ON DUPLICATE KEY UPDATE cursor_user_id=VALUES(cursor_user_id),updated_at_utc=VALUES(updated_at_utc)",
            ['cursor'=>$userId?->value()],
        ));
    }

    public function evaluationCandidates(?EntityId $afterUserId, int $limit): array
    {
        self::limit($limit);
        $where=$afterUserId===null ? '' : ' WHERE user_id>:after_user_id';
        $params=$afterUserId===null ? [] : ['after_user_id'=>$afterUserId->value()];
        return array_map(
            static fn(array $row): EntityId=>UserId::fromStored((string)$row['user_id']),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT user_id FROM forwext_users' . $where . ' ORDER BY user_id LIMIT ' . $limit,
                $params,
            )),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateDefinition(array $row): TrophyDefinition
    {
        try{
            $kind=TrophyKind::from((string)$row['kind']);
            $rule=TrophyRuleType::from((string)$row['rule_type']);
        }catch(ValueError $e){throw new RuntimeException('Stored trophy enum value is invalid.',previous:$e);}
        return new TrophyDefinition(
            EntityId::fromString((string)$row['trophy_id']),
            (string)$row['trophy_key'],(string)$row['name'],(string)$row['description'],$kind,
            (bool)$row['active'],(int)$row['priority'],
            isset($row['icon_path'])&&is_string($row['icon_path'])?$row['icon_path']:null,
            isset($row['banner_path'])&&is_string($row['banner_path'])?$row['banner_path']:null,
            $rule,$row['threshold']===null?null:(int)$row['threshold'],
            self::parse((string)$row['created_at_utc']),self::parse((string)$row['updated_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateGrant(array $row): TrophyGrant
    {
        return new TrophyGrant(
            EntityId::fromString((string)$row['grant_id']),
            EntityId::fromString((string)$row['trophy_id']),
            UserId::fromStored((string)$row['user_id']),
            (string)$row['source'],
            isset($row['awarded_by_user_id'])&&is_string($row['awarded_by_user_id'])?UserId::fromStored($row['awarded_by_user_id']):null,
            self::parse((string)$row['awarded_at_utc']),
            isset($row['revoked_by_user_id'])&&is_string($row['revoked_by_user_id'])?UserId::fromStored($row['revoked_by_user_id']):null,
            isset($row['revoked_at_utc'])&&is_string($row['revoked_at_utc'])?self::parse($row['revoked_at_utc']):null,
            isset($row['reason'])&&is_string($row['reason'])?$row['reason']:null,
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateHistory(array $row): TrophyHistoryEntry
    {
        return new TrophyHistoryEntry(
            (int)$row['history_id'],
            EntityId::fromString((string)$row['grant_id']),
            EntityId::fromString((string)$row['trophy_id']),
            UserId::fromStored((string)$row['user_id']),
            TrophyHistoryAction::from((string)$row['action']),
            (string)$row['source'],
            isset($row['actor_user_id'])&&is_string($row['actor_user_id'])?UserId::fromStored($row['actor_user_id']):null,
            isset($row['reason'])&&is_string($row['reason'])?$row['reason']:null,
            self::parse((string)$row['occurred_at_utc']),
        );
    }

    private static function limit(int $limit): void
    {
        if($limit<1||$limit>500) throw new InvalidArgumentException('Trophy listing limit is invalid.');
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        foreach(['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format){
            $date=DateTimeImmutable::createFromFormat($format,$value,new DateTimeZone('UTC'));
            if($date instanceof DateTimeImmutable)return $date;
        }
        throw new RuntimeException('Stored trophy timestamp is invalid.');
    }
}
