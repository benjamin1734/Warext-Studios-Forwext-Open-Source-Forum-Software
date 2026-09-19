<?php

declare(strict_types=1);

namespace Forwext\Core\Promotion;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Reward\RewardGrant;
use Forwext\Core\Reward\RewardGrantGateway;
use Forwext\Core\Reward\RewardGrantRequest;
use InvalidArgumentException;

final readonly class PromotionService
{
    public function __construct(
        private PromotionRepository $repository,
        private PromotionMetricProvider $metrics,
        private RewardGrantGateway $rewards,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ) {
    }

    /** @return list<PromotionDefinition> */
    public function definitions(EntityId $actor):array
    {
        $this->require($actor,'promotion.manage');
        return $this->repository->definitions(false);
    }

    public function definition(EntityId $actor,EntityId $promotionId):?PromotionDefinition
    {
        $this->require($actor,'promotion.manage');
        return $this->repository->find($promotionId);
    }

    public function save(
        EntityId $actor,PromotionDefinition $definition,DateTimeImmutable $now,?AuditRequestId $requestId=null
    ):void{
        $this->require($actor,'promotion.manage');
        $before=$this->repository->find($definition->promotionId);
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString($before===null?'promotion.create':'promotion.update'),
            'promotion.definition',$definition->promotionId->value(),null,
            $before===null?'promotion.create':'promotion.update',$requestId??AuditRequestId::generate(),
            $before===null?[]:self::snapshot($before),self::snapshot($definition),self::utc($now)
        );
        $this->audit->mutate($event,function()use($definition):void{$this->repository->save($definition);});
    }

    /** @return list<RewardGrant> */
    public function evaluateUser(EntityId $userId,DateTimeImmutable $now):array
    {
        UserId::assert($userId);
        $grants=[];
        foreach($this->repository->definitions(true) as $definition){
            if($this->metrics->metric($userId,$definition->ruleType,$now)<$definition->threshold)continue;
            $grants[]=$this->rewards->grant(new RewardGrantRequest(
                $userId,'promotion',$definition->promotionId->value(),$definition->rewardKey,$definition->units
            ),$now);
        }
        return $grants;
    }

    public function evaluateUserForActor(
        EntityId $actor,EntityId $userId,DateTimeImmutable $now,?AuditRequestId $requestId=null
    ):int{
        $this->require($actor,'promotion.manage');
        $grants=$this->evaluateUser($userId,$now);
        $this->audit->append(new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('promotion.evaluate_user'),
            'promotion.user',$userId->value(),null,'promotion.evaluate_user',$requestId??AuditRequestId::generate(),
            [],['matched_count'=>count($grants)],self::utc($now)
        ));
        return count($grants);
    }

    public function evaluateBatchForActor(
        EntityId $actor,int $limit,DateTimeImmutable $now,?AuditRequestId $requestId=null
    ):int{
        $this->require($actor,'promotion.manage');
        $count=$this->evaluateBatch($limit,$now);
        $this->audit->append(new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('promotion.evaluate_batch'),
            'promotion.batch','rules',null,'promotion.evaluate_batch',$requestId??AuditRequestId::generate(),[],
            ['processed_users'=>$count,'limit'=>$limit],self::utc($now)
        ));
        return $count;
    }

    public function evaluateBatch(int $limit,DateTimeImmutable $now):int
    {
        if($limit<1||$limit>500)throw new InvalidArgumentException('Promotion evaluation batch limit is invalid.');
        $cursor=$this->repository->evaluationCursor();
        $users=$this->repository->evaluationCandidates($cursor,$limit);
        if($users===[]){
            $this->repository->setEvaluationCursor(null);
            return 0;
        }
        $processed=0;
        foreach($users as $userId){
            $this->evaluateUser($userId,$now);
            ++$processed;
        }
        $this->repository->setEvaluationCursor($users[count($users)-1]);
        return $processed;
    }

    private function require(EntityId $actor,string $permission):void
    {
        $decision=$this->authorizer->resolve($actor,PermissionKey::fromString($permission));
        if(!$decision->isAllowed())throw new PermissionDeniedException($decision);
    }

    /** @return array<string,scalar|null> */
    private static function snapshot(PromotionDefinition $definition):array
    {
        return [
            'key'=>$definition->key,'active'=>$definition->active,'priority'=>$definition->priority,
            'rule_type'=>$definition->ruleType->value,'threshold'=>$definition->threshold,
            'reward_key'=>$definition->rewardKey,'units'=>$definition->units
        ];
    }

    private static function utc(DateTimeImmutable $value):DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }
}
