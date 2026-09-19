<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

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
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class TrophyService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private TrophyRepository $repository,
        private TrophyMetricProvider $metrics,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ) {
    }

    /** @return list<TrophyDefinition> */
    public function definitions(EntityId $actor): array
    {
        $this->require($actor,'trophy.manage');
        return $this->repository->definitions(false);
    }

    public function definition(EntityId $actor, EntityId $trophyId): ?TrophyDefinition
    {
        $this->require($actor,'trophy.manage');
        return $this->repository->findDefinition($trophyId);
    }

    public function saveDefinition(
        EntityId $actor,
        TrophyDefinition $definition,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ): void {
        $this->require($actor,'trophy.manage');
        $existing=$this->repository->findDefinition($definition->trophyId);
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString($existing===null?'trophy.create':'trophy.update'),
            'trophy.definition',$definition->trophyId->value(),null,
            $existing===null?'trophy.create':'trophy.update',
            $requestId??AuditRequestId::generate(),
            $existing===null?[]:self::definitionSnapshot($existing),
            self::definitionSnapshot($definition),
            self::utc($now),
        );
        $this->audit->mutate($event,function()use($definition):void{$this->repository->saveDefinition($definition);});
    }

    /** @return list<array{definition:TrophyDefinition,grant:TrophyGrant}> */
    public function profileAwards(?EntityId $viewer, EntityId $userId, int $limit=50): array
    {
        UserId::assert($userId);
        if($viewer===null) return [];
        $this->require($viewer,'trophy.view');
        return $this->repository->activeForUser($userId,$limit);
    }

    /** @return list<TrophyHistoryEntry> */
    public function history(EntityId $viewer, EntityId $userId, int $limit=100, int $offset=0): array
    {
        $this->require($viewer,'trophy.view');
        UserId::assert($userId);
        return $this->repository->historyForUser($userId,$limit,$offset);
    }

    public function awardManual(
        EntityId $actor,
        EntityId $trophyId,
        EntityId $userId,
        string $reason,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ): TrophyGrant {
        $this->require($actor,'trophy.award');
        UserId::assert($userId);
        $definition=$this->repository->findDefinition($trophyId)
            ?? throw new InvalidArgumentException('Trophy definition was not found.');
        if(!$definition->active) throw new InvalidArgumentException('Inactive trophy cannot be awarded.');
        return $this->grant($definition,$userId,'manual',$actor,trim($reason),$now,$requestId);
    }

    public function revoke(
        EntityId $actor,
        EntityId $trophyId,
        EntityId $userId,
        string $reason,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ): TrophyGrant {
        $this->require($actor,'trophy.award');
        UserId::assert($userId);
        $existing=$this->repository->grantForUser($trophyId,$userId);
        if($existing===null||!$existing->active()) throw new InvalidArgumentException('Active trophy grant was not found.');
        $reason=trim($reason);
        if($reason===''||strlen($reason)>500||preg_match('//u',$reason)!==1) throw new InvalidArgumentException('Trophy revoke reason is invalid.');
        $grant=new TrophyGrant(
            $existing->grantId,$existing->trophyId,$existing->userId,$existing->source,$existing->awardedByUserId,
            $existing->awardedAt,$actor,self::utc($now),$reason,
        );
        $history=new TrophyHistoryEntry(
            0,$grant->grantId,$grant->trophyId,$grant->userId,TrophyHistoryAction::Revoked,
            $grant->source,$actor,$reason,self::utc($now),
        );
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('trophy.revoke'),
            'trophy.grant',$grant->grantId->value(),null,'trophy.revoke',$requestId??AuditRequestId::generate(),
            ['trophy_id'=>$trophyId->value(),'user_id'=>$userId->value(),'active'=>true],
            ['trophy_id'=>$trophyId->value(),'user_id'=>$userId->value(),'active'=>false],
            self::utc($now),
        );
        $this->database->transaction(function()use($grant,$history,$event):void{
            $this->repository->saveGrant($grant,$history);
            $this->audit->append($event);
        });
        return $grant;
    }

    public function evaluateUser(EntityId $userId, DateTimeImmutable $now): int
    {
        UserId::assert($userId);
        $awarded=0;
        foreach($this->repository->definitions(true) as $definition){
            if($definition->ruleType===TrophyRuleType::Manual||$definition->threshold===null) continue;
            $existing=$this->repository->grantForUser($definition->trophyId,$userId);
            if($existing!==null&&$existing->active()) continue;
            if($this->metrics->metric($userId,$definition->ruleType,$now)<$definition->threshold) continue;
            $this->grant($definition,$userId,'rule.'.$definition->ruleType->value,null,null,$now,null);
            ++$awarded;
        }
        return $awarded;
    }

    public function evaluateBatch(int $limit, DateTimeImmutable $now): int
    {
        if($limit<1||$limit>500) throw new InvalidArgumentException('Trophy evaluation batch limit is invalid.');
        $cursor=$this->repository->evaluationCursor();
        $users=$this->repository->evaluationCandidates($cursor,$limit);
        if($users===[]){
            $this->repository->setEvaluationCursor(null);
            return 0;
        }
        $awarded=0;
        foreach($users as $userId){
            $awarded+=$this->evaluateUser($userId,$now);
        }
        $this->repository->setEvaluationCursor($users[count($users)-1]);
        return $awarded;
    }

    private function grant(
        TrophyDefinition $definition,
        EntityId $userId,
        string $source,
        ?EntityId $actor,
        ?string $reason,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId,
    ): TrophyGrant {
        $existing=$this->repository->grantForUser($definition->trophyId,$userId);
        if($existing!==null&&$existing->active()) return $existing;
        $reason=$reason===null||trim($reason)===''?null:trim($reason);
        $grant=new TrophyGrant(
            $existing?->grantId??TrophyGrant::generateId(),$definition->trophyId,$userId,$source,$actor,self::utc($now),
            null,null,$reason,
        );
        $history=new TrophyHistoryEntry(
            0,$grant->grantId,$grant->trophyId,$grant->userId,TrophyHistoryAction::Awarded,$source,$actor,$reason,self::utc($now),
        );

        if($actor===null){
            $this->repository->saveGrant($grant,$history);
            return $grant;
        }

        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,AuditAction::fromString('trophy.award'),
            'trophy.grant',$grant->grantId->value(),null,'trophy.award',$requestId??AuditRequestId::generate(),
            [],['trophy_id'=>$definition->trophyId->value(),'user_id'=>$userId->value(),'source'=>$source],
            self::utc($now),
        );
        $this->database->transaction(function()use($grant,$history,$event):void{
            $this->repository->saveGrant($grant,$history);
            $this->audit->append($event);
        });
        return $grant;
    }

    private function require(EntityId $actor,string $permission):void
    {
        $decision=$this->authorizer->resolve($actor,PermissionKey::fromString($permission));
        if(!$decision->isAllowed()) throw new PermissionDeniedException($decision);
    }

    /** @return array<string,scalar|null> */
    private static function definitionSnapshot(TrophyDefinition $definition):array
    {
        return [
            'key'=>$definition->key,'kind'=>$definition->kind->value,'active'=>$definition->active,
            'priority'=>$definition->priority,'rule_type'=>$definition->ruleType->value,'threshold'=>$definition->threshold,
            'has_icon'=>$definition->iconPath!==null,'has_banner'=>$definition->bannerPath!==null,
        ];
    }

    private static function utc(DateTimeImmutable $value):DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }
}
