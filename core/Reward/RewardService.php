<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class RewardService implements RewardGrantGateway
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private RewardRepository $repository,
        private RewardProviderRegistry $providers,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ) {
    }

    public function grant(RewardGrantRequest $request, DateTimeImmutable $now): RewardGrant
    {
        $now=self::utc($now);
        return $this->database->transaction(function()use($request,$now):RewardGrant{
            if(!$this->repository->lockUser($request->recipientUserId)){
                throw new InvalidArgumentException('Reward recipient was not found.');
            }
            $existing=$this->repository->grantByRequest($request);
            if($existing!==null&&$existing->state===RewardGrantState::Applied){
                return $existing;
            }

            $definition=$this->repository->definitionByKey($request->rewardKey);
            $grantId=$existing?->grantId??RewardGrant::generateId();
            $created=$existing?->createdAt??$now;
            if($definition===null||!$definition->active){
                $grant=new RewardGrant(
                    $grantId,$request->recipientUserId,$request->sourceType,$request->sourceId,$request->rewardKey,
                    $request->units,RewardGrantState::Pending,$definition?->rewardId,$definition?->providerKey,
                    $definition?->targetId,'definition_unavailable',$created
                );
                $this->repository->saveGrant($grant);
                return $grant;
            }

            $provider=$this->providers->find($definition->providerKey);
            if($provider===null){
                $grant=new RewardGrant(
                    $grantId,$request->recipientUserId,$request->sourceType,$request->sourceId,$request->rewardKey,
                    $request->units,RewardGrantState::Failed,$definition->rewardId,$definition->providerKey,
                    $definition->targetId,'provider_unavailable',$created
                );
                $this->repository->saveGrant($grant);
                return $grant;
            }

            try{
                $result=$provider->apply($request->recipientUserId,$definition->targetId,$request->units,$now);
                if($result->createdAssignment){
                    $this->repository->setAssignmentManaged(
                        $request->recipientUserId,$definition->providerKey,$definition->targetId,true
                    );
                }
                $grant=new RewardGrant(
                    $grantId,$request->recipientUserId,$request->sourceType,$request->sourceId,$request->rewardKey,
                    $request->units,RewardGrantState::Applied,$definition->rewardId,$definition->providerKey,
                    $definition->targetId,null,$created,$now
                );
            }catch(Throwable){
                $grant=new RewardGrant(
                    $grantId,$request->recipientUserId,$request->sourceType,$request->sourceId,$request->rewardKey,
                    $request->units,RewardGrantState::Failed,$definition->rewardId,$definition->providerKey,
                    $definition->targetId,'provider_error',$created
                );
            }
            $this->repository->saveGrant($grant);
            return $grant;
        });
    }

    public function fulfillBindings(
        string $sourceType,
        EntityId $sourceDefinitionId,
        string $sourceEventId,
        EntityId $recipientUserId,
        DateTimeImmutable $now,
    ): array {
        $grants=[];
        foreach($this->repository->bindings($sourceType,$sourceDefinitionId,true) as $binding){
            $grants[]=$this->grant(new RewardGrantRequest(
                $recipientUserId,$sourceType,$sourceEventId,$binding->rewardKey,$binding->units
            ),$now);
        }
        return $grants;
    }

    public function revokeSource(
        string $sourceType,
        string $sourceEventId,
        EntityId $recipientUserId,
        DateTimeImmutable $now,
    ): array {
        $now=self::utc($now);
        $changed=[];
        foreach($this->repository->grantsBySource($sourceType,$sourceEventId,$recipientUserId) as $grant){
            if($grant->state!==RewardGrantState::Applied||$grant->providerKey===null||$grant->targetId===null)continue;
            $changed[]=$this->database->transaction(function()use($grant,$now):RewardGrant{
                if(!$this->repository->lockUser($grant->recipientUserId)){
                    return $grant;
                }
                $revoked=new RewardGrant(
                    $grant->grantId,$grant->recipientUserId,$grant->sourceType,$grant->sourceId,$grant->rewardKey,
                    $grant->units,RewardGrantState::Revoked,$grant->definitionId,$grant->providerKey,$grant->targetId,
                    null,$grant->createdAt,$grant->appliedAt,$now
                );
                $this->repository->saveGrant($revoked);

                if($this->repository->activeEntitlementCount(
                    $grant->recipientUserId,$grant->providerKey,$grant->targetId
                )===0 && $this->repository->assignmentManaged(
                    $grant->recipientUserId,$grant->providerKey,$grant->targetId
                )){
                    $provider=$this->providers->find($grant->providerKey);
                    if($provider===null)throw new InvalidArgumentException('Reward provider is unavailable for revocation.');
                    $provider->revoke($grant->recipientUserId,$grant->targetId,$grant->units,$now);
                    $this->repository->setAssignmentManaged(
                        $grant->recipientUserId,$grant->providerKey,$grant->targetId,false
                    );
                }
                return $revoked;
            });
        }
        return $changed;
    }

    /** @return array{definitions:list<RewardDefinition>,bindings:list<RewardBinding>,retryable:list<RewardGrant>,providers:list<string>,targets:array<string,list<RewardTargetOption>>,giveaways:list<RewardSourceOption>,trophies:list<RewardSourceOption>} */
    public function managementSnapshot(EntityId $actor):array
    {
        $this->require($actor,'reward.manage');
        $targets=[];
        foreach($this->providers->keys() as $providerKey){
            $provider=$this->providers->find($providerKey);
            $targets[$providerKey]=$provider?->targets()??[];
        }
        return [
            'definitions'=>$this->repository->definitions(),
            'bindings'=>$this->repository->allBindings(),
            'retryable'=>$this->repository->retryable(100),
            'providers'=>$this->providers->keys(),
            'targets'=>$targets,
            'giveaways'=>$this->repository->sourceOptions('giveaway'),
            'trophies'=>$this->repository->sourceOptions('trophy'),
        ];
    }

    public function definition(EntityId $actor,EntityId $rewardId):?RewardDefinition
    {
        $this->require($actor,'reward.manage');
        return $this->repository->definition($rewardId);
    }

    public function binding(EntityId $actor,EntityId $bindingId):?RewardBinding
    {
        $this->require($actor,'reward.manage');
        return $this->repository->binding($bindingId);
    }

    public function saveDefinition(
        EntityId $actor,RewardDefinition $definition,DateTimeImmutable $now,?AuditRequestId $requestId=null
    ):void{
        $this->require($actor,'reward.manage');
        $provider=$this->providers->find($definition->providerKey);
        if($provider===null){
            throw new InvalidArgumentException('Unknown reward provider.');
        }
        if(!$provider->supportsTarget($definition->targetId)){
            throw new InvalidArgumentException('Reward target is not eligible for automatic assignment.');
        }
        $before=$this->repository->definition($definition->rewardId);
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString($before===null?'reward.create':'reward.update'),
            'reward.definition',$definition->rewardId->value(),null,
            $before===null?'reward.create':'reward.update',$requestId??AuditRequestId::generate(),
            $before===null?[]:self::definitionSnapshot($before),self::definitionSnapshot($definition),self::utc($now)
        );
        $this->audit->mutate($event,function()use($definition):void{$this->repository->saveDefinition($definition);});
    }

    public function saveBinding(
        EntityId $actor,RewardBinding $binding,DateTimeImmutable $now,?AuditRequestId $requestId=null
    ):void{
        $this->require($actor,'reward.manage');
        if(!in_array($binding->sourceType,['giveaway','trophy'],true)){
            throw new InvalidArgumentException('Reward bindings only support first-party giveaway or trophy definitions.');
        }
        if($this->repository->definitionByKey($binding->rewardKey)===null){
            throw new InvalidArgumentException('Reward binding references an unknown reward definition.');
        }
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('reward.binding.save'),
            'reward.binding',$binding->bindingId->value(),null,'reward.binding.save',$requestId??AuditRequestId::generate(),
            [],[
                'source_type'=>$binding->sourceType,'source_definition_id'=>$binding->sourceDefinitionId->value(),
                'reward_key'=>$binding->rewardKey,'units'=>$binding->units,'active'=>$binding->active,
            ],self::utc($now)
        );
        $this->audit->mutate($event,function()use($binding):void{$this->repository->saveBinding($binding);});
    }

    public function retry(
        EntityId $actor,EntityId $grantId,DateTimeImmutable $now,?AuditRequestId $requestId=null
    ):RewardGrant{
        $this->require($actor,'reward.manage');
        $grant=$this->repository->grant($grantId)??throw new InvalidArgumentException('Reward grant was not found.');
        if(!in_array($grant->state,[RewardGrantState::Pending,RewardGrantState::Failed],true)){
            throw new InvalidArgumentException('Reward grant is not retryable.');
        }
        $after=$this->grant(new RewardGrantRequest(
            $grant->recipientUserId,$grant->sourceType,$grant->sourceId,$grant->rewardKey,$grant->units
        ),$now);
        $this->audit->append(new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('reward.retry'),
            'reward.grant',$grantId->value(),null,'reward.retry',$requestId??AuditRequestId::generate(),
            ['state'=>$grant->state->value],['state'=>$after->state->value],self::utc($now)
        ));
        return $after;
    }

    public function retryDueForActor(
        EntityId $actor,int $limit,DateTimeImmutable $now,?AuditRequestId $requestId=null
    ):int{
        $this->require($actor,'reward.manage');
        $count=$this->retryDue($limit,$now);
        $this->audit->append(new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('reward.retry_batch'),
            'reward.batch','retry',null,'reward.retry_batch',$requestId??AuditRequestId::generate(),[],
            ['applied_count'=>$count,'limit'=>$limit],self::utc($now)
        ));
        return $count;
    }

    public function retryDue(int $limit,DateTimeImmutable $now):int
    {
        if($limit<1||$limit>500)throw new InvalidArgumentException('Reward retry limit is invalid.');
        $applied=0;
        foreach($this->repository->retryable($limit) as $grant){
            $after=$this->grant(new RewardGrantRequest(
                $grant->recipientUserId,$grant->sourceType,$grant->sourceId,$grant->rewardKey,$grant->units
            ),$now);
            if($after->state===RewardGrantState::Applied)++$applied;
        }
        return $applied;
    }

    private function require(EntityId $actor,string $permission):void
    {
        $decision=$this->authorizer->resolve($actor,PermissionKey::fromString($permission));
        if(!$decision->isAllowed())throw new PermissionDeniedException($decision);
    }

    /** @return array<string,scalar|null> */
    private static function definitionSnapshot(RewardDefinition $definition):array
    {
        return [
            'key'=>$definition->key,'active'=>$definition->active,'provider'=>$definition->providerKey,
            'target_id'=>$definition->targetId->value(),
        ];
    }

    private static function utc(DateTimeImmutable $value):DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }
}
