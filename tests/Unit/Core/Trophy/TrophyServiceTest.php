<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Trophy;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Scheduler\SchedulerRegistry;
use Forwext\Core\Trophy\TrophyDefinition;
use Forwext\Core\Trophy\TrophyGrant;
use Forwext\Core\Trophy\TrophyHistoryAction;
use Forwext\Core\Trophy\TrophyHistoryEntry;
use Forwext\Core\Trophy\TrophyKind;
use Forwext\Core\Trophy\TrophyMaintenanceTasks;
use Forwext\Core\Trophy\TrophyMetricProvider;
use Forwext\Core\Trophy\TrophyRepository;
use Forwext\Core\Trophy\TrophyRuleType;
use Forwext\Core\Trophy\TrophyService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TrophyServiceTest extends TestCase
{
    public function testRuleEvaluationIsIdempotentAndManualRevocationBlocksAutomaticReaward(): void
    {
        $repo=new MemoryTrophyRepository();
        $definition=$this->definition(TrophyRuleType::VisiblePostCount,10);
        $repo->saveDefinition($definition);
        $user=UserId::generate();
        $manager=UserId::generate();
        $service=$this->service($repo,[$user->value()=>12],[$manager->value()=>[
            'trophy.manage'=>true,'trophy.award'=>true,'trophy.view'=>true,
        ]]);

        self::assertSame(1,$service->evaluateUser($user,$this->at('2026-09-19 18:00:00')));
        self::assertSame(0,$service->evaluateUser($user,$this->at('2026-09-19 18:01:00')));
        self::assertCount(1,$repo->historyForUser($user));

        $service->revoke($manager,$definition->trophyId,$user,'Abuse review',$this->at('2026-09-19 18:02:00'));
        self::assertSame(0,$service->evaluateUser($user,$this->at('2026-09-19 18:03:00')));
        self::assertFalse($repo->grantForUser($definition->trophyId,$user)?->active()??true);

        $service->awardManual($manager,$definition->trophyId,$user,'Manual restore',$this->at('2026-09-19 18:04:00'));
        self::assertTrue($repo->grantForUser($definition->trophyId,$user)?->active()??false);
        self::assertCount(3,$repo->historyForUser($user));
    }

    public function testPriorityProfileOrderingAndHistoryAreExposedToAuthorizedViewer(): void
    {
        $repo=new MemoryTrophyRepository();
        $low=$this->definition(TrophyRuleType::Manual,null,10,'low');
        $high=$this->definition(TrophyRuleType::Manual,null,500,'high');
        $repo->saveDefinition($low);
        $repo->saveDefinition($high);
        $user=UserId::generate();
        $viewer=UserId::generate();
        $manager=UserId::generate();
        $service=$this->service($repo,[],[
            $viewer->value()=>['trophy.view'=>true],
            $manager->value()=>['trophy.award'=>true,'trophy.manage'=>true,'trophy.view'=>true],
        ]);
        $service->awardManual($manager,$low->trophyId,$user,'', $this->at('2026-09-19 18:00:00'));
        $service->awardManual($manager,$high->trophyId,$user,'', $this->at('2026-09-19 18:01:00'));

        $awards=$service->profileAwards($viewer,$user);
        self::assertSame('high',$awards[0]['definition']->key);
        self::assertSame('low',$awards[1]['definition']->key);
        self::assertCount(2,$service->profileHistory($viewer,$user));
    }

    public function testAssetPathsRejectProtocolRelativeSources(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TrophyDefinition(
            TrophyDefinition::generateId(),'unsafe','Unsafe','',TrophyKind::Badge,true,100,
            '//evil.example/icon.png',null,TrophyRuleType::Manual,null,
            $this->at('2026-09-19 18:00:00'),$this->at('2026-09-19 18:00:00')
        );
    }

    public function testMaintenanceScheduleIsBoundedHourlyEvaluation(): void
    {
        $registry=new SchedulerRegistry();
        TrophyMaintenanceTasks::register($registry);
        $tasks=$registry->all();
        self::assertCount(1,$tasks);
        self::assertSame('trophy.evaluate',$tasks[0]->jobType);
        self::assertSame('{"limit":200}',$tasks[0]->payload);
    }

    private function definition(
        TrophyRuleType $ruleType,
        ?int $threshold,
        int $priority=100,
        string $key='posts-10',
    ):TrophyDefinition{
        $at=$this->at('2026-09-19 17:00:00');
        return new TrophyDefinition(
            TrophyDefinition::generateId(),$key,ucfirst($key),'desc',TrophyKind::Badge,true,$priority,
            '/assets/trophies/icon.webp','/assets/trophies/banner.webp',$ruleType,$threshold,$at,$at
        );
    }

    /** @param array<string,int> $metrics @param array<string,array<string,bool>> $permissions */
    private function service(MemoryTrophyRepository $repo,array $metrics,array $permissions):TrophyService
    {
        return new TrophyService(
            new TrophyTestDatabase(),
            $repo,
            new StaticTrophyMetrics($metrics),
            new PermissionAuthorizer(
                new PermissionEngine(new TrophyPermissionRules($permissions)),
                new TrophyAssignments(array_keys($permissions)),
            ),
            new TrophyAuditRecorder(),
        );
    }

    private function at(string $value):DateTimeImmutable
    {
        return new DateTimeImmutable($value,new DateTimeZone('UTC'));
    }
}

final class StaticTrophyMetrics implements TrophyMetricProvider
{
    /** @param array<string,int> $values */
    public function __construct(private array $values){}
    public function metric(EntityId $userId,TrophyRuleType $ruleType,DateTimeImmutable $now):int
    {
        return $this->values[$userId->value()]??0;
    }
}

final class MemoryTrophyRepository implements TrophyRepository
{
    /** @var array<string,TrophyDefinition> */
    private array $definitions=[];
    /** @var array<string,TrophyGrant> */
    private array $grants=[];
    /** @var list<TrophyHistoryEntry> */
    private array $history=[];
    private ?EntityId $cursor=null;

    public function findDefinition(EntityId $trophyId):?TrophyDefinition{return $this->definitions[$trophyId->value()]??null;}
    public function definitions(bool $activeOnly=false,int $limit=500):array
    {
        $items=array_values(array_filter($this->definitions,static fn(TrophyDefinition $d):bool=>!$activeOnly||$d->active));
        usort($items,static fn(TrophyDefinition $a,TrophyDefinition $b):int=>$b->priority<=>$a->priority);
        return array_slice($items,0,$limit);
    }
    public function saveDefinition(TrophyDefinition $definition):void{$this->definitions[$definition->trophyId->value()]=$definition;}
    public function grantForUser(EntityId $trophyId,EntityId $userId):?TrophyGrant{return $this->grants[$trophyId->value().':'.$userId->value()]??null;}
    public function activeForUser(EntityId $userId,int $limit=100):array
    {
        $items=[];
        foreach($this->grants as $grant){
            if(!$grant->userId->equals($userId)||!$grant->active())continue;
            $definition=$this->definitions[$grant->trophyId->value()]??null;
            if($definition!==null&&$definition->active)$items[]=['definition'=>$definition,'grant'=>$grant];
        }
        usort($items,static fn(array $a,array $b):int=>$b['definition']->priority<=>$a['definition']->priority);
        return array_slice($items,0,$limit);
    }
    public function historyForUser(EntityId $userId,int $limit=100,int $offset=0):array
    {
        $items=array_values(array_filter($this->history,static fn(TrophyHistoryEntry $h):bool=>$h->userId->equals($userId)));
        usort($items,static fn(TrophyHistoryEntry $a,TrophyHistoryEntry $b):int=>$b->occurredAt<=>$a->occurredAt);
        return array_slice($items,$offset,$limit);
    }
    public function saveGrant(TrophyGrant $grant,TrophyHistoryEntry $history):void
    {
        $this->grants[$grant->trophyId->value().':'.$grant->userId->value()]=$grant;
        $this->history[]=new TrophyHistoryEntry(
            count($this->history)+1,$history->grantId,$history->trophyId,$history->userId,$history->action,
            $history->source,$history->actorUserId,$history->reason,$history->occurredAt
        );
    }
    public function evaluationCursor():?EntityId{return $this->cursor;}
    public function setEvaluationCursor(?EntityId $userId):void{$this->cursor=$userId;}
    public function evaluationCandidates(?EntityId $afterUserId,int $limit):array{return [];}
}

final class TrophyPermissionRules implements PermissionRuleRepository
{
    /** @param array<string,array<string,bool>> $permissions */
    public function __construct(private array $permissions){}
    public function definition(PermissionKey $key):?PermissionDefinition{return new PermissionDefinition($key,PermissionValueType::Flag);}
    public function rules(PermissionKey $key,UserAccessAssignment $assignment,?EntityId $nodeId):array
    {
        $allowed=$this->permissions[$assignment->userId()->value()][$key->value()]??false;
        return [new PermissionRule(PermissionSubjectType::User,$assignment->userId(),$allowed?PermissionEffect::Allow:PermissionEffect::Deny)];
    }
}

final class TrophyAssignments implements UserAccessAssignmentProvider
{
    /** @var array<string,true> */
    private array $ids;
    /** @param list<string> $ids */
    public function __construct(array $ids){$this->ids=array_fill_keys($ids,true);}
    public function find(EntityId $userId):?UserAccessAssignment
    {
        return isset($this->ids[$userId->value()])
            ?new UserAccessAssignment($userId,EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'))
            :null;
    }
}

final class TrophyAuditRecorder implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events=[];
    public function append(AuditEvent $event):void{$this->events[]=$event;}
    public function mutate(AuditEvent $event,callable $mutation):mixed{$result=$mutation();$this->events[]=$event;return $result;}
}

final class TrophyTestDatabase implements TransactionalQueryExecutor
{
    private int $depth=0;
    public function execute(CompiledQuery $query):int{return 1;}
    public function fetchOne(CompiledQuery $query):?array{return null;}
    public function fetchAll(CompiledQuery $query):array{return [];}
    public function fetchValue(CompiledQuery $query):mixed{return null;}
    public function inTransaction():bool{return $this->depth>0;}
    public function transaction(Closure $callback):mixed
    {
        ++$this->depth;
        try{return $callback($this);}finally{--$this->depth;}
    }
}
