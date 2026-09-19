<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final readonly class DatabaseRewardRepository implements RewardRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function lockUser(EntityId $userId): bool
    {
        UserId::assert($userId);
        return $this->database->fetchOne(new CompiledQuery(
            'SELECT user_id FROM forwext_users WHERE user_id=:user_id FOR UPDATE',
            ['user_id'=>$userId->value()],
            true,
        )) !== null;
    }

    public function definitionByKey(string $rewardKey): ?RewardDefinition
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_reward_definitions WHERE reward_key=:reward_key LIMIT 1',
            ['reward_key'=>$rewardKey],
        ));
        return $row===null?null:$this->hydrateDefinition($row);
    }

    public function definition(EntityId $rewardId): ?RewardDefinition
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_reward_definitions WHERE reward_id=:reward_id LIMIT 1',
            ['reward_id'=>$rewardId->value()],
        ));
        return $row===null?null:$this->hydrateDefinition($row);
    }

    public function definitions(int $limit=500):array
    {
        self::limit($limit);
        return array_map(
            $this->hydrateDefinition(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT * FROM forwext_reward_definitions ORDER BY reward_key LIMIT '.$limit,
            )),
        );
    }

    public function saveDefinition(RewardDefinition $definition):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_reward_definitions '
            . '(reward_id,reward_key,name,active,provider_key,target_id,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:key,:name,:active,:provider,:target,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE reward_key=VALUES(reward_key),name=VALUES(name),active=VALUES(active),'
            . 'provider_key=VALUES(provider_key),target_id=VALUES(target_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$definition->rewardId->value(),'key'=>$definition->key,'name'=>$definition->name,
                'active'=>$definition->active?1:0,'provider'=>$definition->providerKey,'target'=>$definition->targetId->value(),
                'created'=>self::format($definition->createdAt),'updated'=>self::format($definition->updatedAt),
            ],
        ));
    }

    public function grantByRequest(RewardGrantRequest $request):?RewardGrant
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_reward_grants WHERE recipient_user_id=:user_id AND source_type=:source_type '
            . 'AND source_id=:source_id AND reward_key=:reward_key LIMIT 1',
            [
                'user_id'=>$request->recipientUserId->value(),'source_type'=>$request->sourceType,
                'source_id'=>$request->sourceId,'reward_key'=>$request->rewardKey,
            ],
        ));
        return $row===null?null:$this->hydrateGrant($row);
    }

    public function grant(EntityId $grantId):?RewardGrant
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_reward_grants WHERE grant_id=:grant_id LIMIT 1',
            ['grant_id'=>$grantId->value()],
        ));
        return $row===null?null:$this->hydrateGrant($row);
    }

    public function grantsBySource(string $sourceType,string $sourceId,EntityId $recipientUserId):array
    {
        return array_map(
            $this->hydrateGrant(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT * FROM forwext_reward_grants WHERE source_type=:source_type AND source_id=:source_id '
                . 'AND recipient_user_id=:user_id ORDER BY grant_id',
                ['source_type'=>$sourceType,'source_id'=>$sourceId,'user_id'=>$recipientUserId->value()],
            )),
        );
    }

    public function retryable(int $limit=100):array
    {
        self::limit($limit);
        return array_map(
            $this->hydrateGrant(...),
            $this->database->fetchAll(new CompiledQuery(
                "SELECT * FROM forwext_reward_grants WHERE state IN ('pending','failed') ORDER BY created_at_utc,grant_id LIMIT ".$limit,
            )),
        );
    }

    public function saveGrant(RewardGrant $grant):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_reward_grants '
            . '(grant_id,definition_id,recipient_user_id,source_type,source_id,reward_key,units,state,provider_key,target_id,'
            . 'failure_code,created_at_utc,applied_at_utc,revoked_at_utc) '
            . 'VALUES (:grant_id,:definition_id,:user_id,:source_type,:source_id,:reward_key,:units,:state,:provider,:target,'
            . ':failure,:created,:applied,:revoked) '
            . 'ON DUPLICATE KEY UPDATE definition_id=VALUES(definition_id),units=VALUES(units),state=VALUES(state),'
            . 'provider_key=VALUES(provider_key),target_id=VALUES(target_id),failure_code=VALUES(failure_code),'
            . 'applied_at_utc=VALUES(applied_at_utc),revoked_at_utc=VALUES(revoked_at_utc)',
            [
                'grant_id'=>$grant->grantId->value(),'definition_id'=>$grant->definitionId?->value(),
                'user_id'=>$grant->recipientUserId->value(),'source_type'=>$grant->sourceType,'source_id'=>$grant->sourceId,
                'reward_key'=>$grant->rewardKey,'units'=>$grant->units,'state'=>$grant->state->value,
                'provider'=>$grant->providerKey,'target'=>$grant->targetId?->value(),'failure'=>$grant->failureCode,
                'created'=>self::format($grant->createdAt),'applied'=>$grant->appliedAt===null?null:self::format($grant->appliedAt),
                'revoked'=>$grant->revokedAt===null?null:self::format($grant->revokedAt),
            ],
        ));
    }

    public function activeEntitlementCount(EntityId $userId,string $providerKey,EntityId $targetId):int
    {
        return (int)$this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_reward_grants WHERE recipient_user_id=:user_id AND provider_key=:provider "
            . "AND target_id=:target AND state='applied'",
            ['user_id'=>$userId->value(),'provider'=>$providerKey,'target'=>$targetId->value()],
        ));
    }

    public function assignmentManaged(EntityId $userId,string $providerKey,EntityId $targetId):bool
    {
        return (bool)$this->database->fetchValue(new CompiledQuery(
            'SELECT managed_by_reward FROM forwext_reward_assignment_ownership '
            . 'WHERE recipient_user_id=:user_id AND provider_key=:provider AND target_id=:target LIMIT 1',
            ['user_id'=>$userId->value(),'provider'=>$providerKey,'target'=>$targetId->value()],
        ));
    }

    public function setAssignmentManaged(EntityId $userId,string $providerKey,EntityId $targetId,bool $managed):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_reward_assignment_ownership '
            . '(recipient_user_id,provider_key,target_id,managed_by_reward,updated_at_utc) '
            . 'VALUES (:user_id,:provider,:target,:managed,UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE managed_by_reward=VALUES(managed_by_reward),updated_at_utc=VALUES(updated_at_utc)',
            ['user_id'=>$userId->value(),'provider'=>$providerKey,'target'=>$targetId->value(),'managed'=>$managed?1:0],
        ));
    }

    public function bindings(string $sourceType,EntityId $sourceDefinitionId,bool $activeOnly=true):array
    {
        $where=$activeOnly?' AND active=1':'';
        return array_map(
            $this->hydrateBinding(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT * FROM forwext_reward_bindings WHERE source_type=:source_type AND source_definition_id=:source_id'
                .$where.' ORDER BY reward_key,binding_id',
                ['source_type'=>$sourceType,'source_id'=>$sourceDefinitionId->value()],
            )),
        );
    }

    public function allBindings(int $limit=500):array
    {
        self::limit($limit);
        return array_map(
            $this->hydrateBinding(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT * FROM forwext_reward_bindings ORDER BY source_type,source_definition_id,reward_key LIMIT '.$limit,
            )),
        );
    }

    public function saveBinding(RewardBinding $binding):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_reward_bindings(binding_id,source_type,source_definition_id,reward_key,units,active) '
            . 'VALUES (:id,:source_type,:source_id,:reward_key,:units,:active) '
            . 'ON DUPLICATE KEY UPDATE reward_key=VALUES(reward_key),units=VALUES(units),active=VALUES(active)',
            [
                'id'=>$binding->bindingId->value(),'source_type'=>$binding->sourceType,
                'source_id'=>$binding->sourceDefinitionId->value(),'reward_key'=>$binding->rewardKey,
                'units'=>$binding->units,'active'=>$binding->active?1:0,
            ],
        ));
    }

    public function sourceOptions(string $sourceType): array
    {
        $rows=match($sourceType){
            'giveaway'=>$this->database->fetchAll(new CompiledQuery(
                'SELECT giveaway_id AS source_id,title AS label FROM forwext_giveaways ORDER BY created_at_utc DESC LIMIT 500'
            )),
            'trophy'=>$this->database->fetchAll(new CompiledQuery(
                'SELECT trophy_id AS source_id,name AS label FROM forwext_trophies ORDER BY priority DESC,name LIMIT 500'
            )),
            default=>throw new InvalidArgumentException('Unsupported reward binding source type.'),
        };
        return array_map(
            static fn(array $row):RewardSourceOption=>new RewardSourceOption(
                EntityId::fromString((string)$row['source_id']),
                (string)$row['label'],
            ),
            $rows
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateDefinition(array $row):RewardDefinition
    {
        return new RewardDefinition(
            EntityId::fromString((string)$row['reward_id']),(string)$row['reward_key'],(string)$row['name'],
            (bool)$row['active'],(string)$row['provider_key'],EntityId::fromString((string)$row['target_id']),
            self::parse((string)$row['created_at_utc']),self::parse((string)$row['updated_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateBinding(array $row):RewardBinding
    {
        return new RewardBinding(
            EntityId::fromString((string)$row['binding_id']),(string)$row['source_type'],
            EntityId::fromString((string)$row['source_definition_id']),(string)$row['reward_key'],
            (int)$row['units'],(bool)$row['active'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateGrant(array $row):RewardGrant
    {
        try{$state=RewardGrantState::from((string)$row['state']);}
        catch(ValueError $e){throw new RuntimeException('Stored reward grant state is invalid.',previous:$e);}
        return new RewardGrant(
            EntityId::fromString((string)$row['grant_id']),UserId::fromStored((string)$row['recipient_user_id']),
            (string)$row['source_type'],(string)$row['source_id'],(string)$row['reward_key'],(int)$row['units'],$state,
            isset($row['definition_id'])&&is_string($row['definition_id'])?EntityId::fromString($row['definition_id']):null,
            isset($row['provider_key'])&&is_string($row['provider_key'])?$row['provider_key']:null,
            isset($row['target_id'])&&is_string($row['target_id'])?EntityId::fromString($row['target_id']):null,
            isset($row['failure_code'])&&is_string($row['failure_code'])?$row['failure_code']:null,
            self::parse((string)$row['created_at_utc']),
            isset($row['applied_at_utc'])&&is_string($row['applied_at_utc'])?self::parse($row['applied_at_utc']):null,
            isset($row['revoked_at_utc'])&&is_string($row['revoked_at_utc'])?self::parse($row['revoked_at_utc']):null,
        );
    }

    private static function limit(int $limit):void
    {
        if($limit<1||$limit>500)throw new InvalidArgumentException('Reward listing limit is invalid.');
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
        throw new RuntimeException('Stored reward timestamp is invalid.');
    }
}
