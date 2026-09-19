<?php

declare(strict_types=1);

namespace Forwext\Core\Promotion;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final readonly class DatabasePromotionRepository implements PromotionRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function find(EntityId $promotionId):?PromotionDefinition
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_promotions WHERE promotion_id=:id LIMIT 1',
            ['id'=>$promotionId->value()]
        ));
        return $row===null?null:$this->hydrate($row);
    }

    public function definitions(bool $activeOnly=false,int $limit=500):array
    {
        self::limit($limit);
        $where=$activeOnly?' WHERE active=1':'';
        return array_map(
            $this->hydrate(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT * FROM forwext_promotions'.$where.' ORDER BY priority DESC,promotion_key,promotion_id LIMIT '.$limit
            ))
        );
    }

    public function save(PromotionDefinition $definition):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_promotions '
            . '(promotion_id,promotion_key,name,active,priority,rule_type,threshold,reward_key,units,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:key,:name,:active,:priority,:rule_type,:threshold,:reward_key,:units,:revoke_when_unqualified,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE promotion_key=VALUES(promotion_key),name=VALUES(name),active=VALUES(active),'
            . 'priority=VALUES(priority),rule_type=VALUES(rule_type),threshold=VALUES(threshold),'
            . 'reward_key=VALUES(reward_key),units=VALUES(units),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$definition->promotionId->value(),'key'=>$definition->key,'name'=>$definition->name,
                'active'=>$definition->active?1:0,'priority'=>$definition->priority,
                'rule_type'=>$definition->ruleType->value,'threshold'=>$definition->threshold,
                'reward_key'=>$definition->rewardKey,'units'=>$definition->units,
                'revoke_when_unqualified'=>$definition->revokeWhenUnqualified?1:0,
                'created'=>self::format($definition->createdAt),'updated'=>self::format($definition->updatedAt)
            ]
        ));
    }

    public function evaluationCursor():?EntityId
    {
        $value=$this->database->fetchValue(new CompiledQuery(
            "SELECT cursor_user_id FROM forwext_promotion_runtime_state WHERE state_key='rule_evaluation' LIMIT 1"
        ));
        return is_string($value)&&$value!==''?UserId::fromStored($value):null;
    }

    public function setEvaluationCursor(?EntityId $userId):void
    {
        $this->database->execute(new CompiledQuery(
            "INSERT INTO forwext_promotion_runtime_state(state_key,cursor_user_id,updated_at_utc) "
            . "VALUES ('rule_evaluation',:cursor,UTC_TIMESTAMP(6)) "
            . "ON DUPLICATE KEY UPDATE cursor_user_id=VALUES(cursor_user_id),updated_at_utc=VALUES(updated_at_utc)",
            ['cursor'=>$userId?->value()]
        ));
    }

    public function evaluationCandidates(?EntityId $afterUserId,int $limit):array
    {
        self::limit($limit);
        $where=$afterUserId===null?'':' WHERE user_id>:after_user_id';
        $params=$afterUserId===null?[]:['after_user_id'=>$afterUserId->value()];
        return array_map(
            static fn(array $row):EntityId=>UserId::fromStored((string)$row['user_id']),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT user_id FROM forwext_users'.$where.' ORDER BY user_id LIMIT '.$limit,$params
            ))
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row):PromotionDefinition
    {
        try{$rule=PromotionRuleType::from((string)$row['rule_type']);}
        catch(ValueError $e){throw new RuntimeException('Stored promotion rule type is invalid.',previous:$e);}
        return new PromotionDefinition(
            EntityId::fromString((string)$row['promotion_id']),(string)$row['promotion_key'],(string)$row['name'],
            (bool)$row['active'],(int)$row['priority'],$rule,(int)$row['threshold'],(string)$row['reward_key'],
            (int)$row['units'],(bool)$row['revoke_when_unqualified'],self::parse((string)$row['created_at_utc']),self::parse((string)$row['updated_at_utc'])
        );
    }

    private static function limit(int $limit):void
    {
        if($limit<1||$limit>500)throw new InvalidArgumentException('Promotion listing limit is invalid.');
    }

    private static function format(DateTimeImmutable $value):string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value):DateTimeImmutable
    {
        foreach(['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format){
            $date=DateTimeImmutable::createFromFormat($format,$value,new DateTimeZone('UTC'));
            if($date instanceof DateTimeImmutable)return $date;
        }
        throw new RuntimeException('Stored promotion timestamp is invalid.');
    }
}
