<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Reward;

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
use Forwext\Core\Promotion\PromotionDefinition;
use Forwext\Core\Promotion\PromotionMaintenanceTasks;
use Forwext\Core\Promotion\PromotionMetricProvider;
use Forwext\Core\Promotion\PromotionRepository;
use Forwext\Core\Promotion\PromotionRuleType;
use Forwext\Core\Promotion\PromotionService;
use Forwext\Core\Reward\DatabaseRoleRewardProvider;
use Forwext\Core\Reward\DatabaseSecondaryGroupRewardProvider;
use Forwext\Core\Reward\RewardGrant;
use Forwext\Core\Reward\RewardGrantGateway;
use Forwext\Core\Reward\RewardGrantRequest;
use Forwext\Core\Reward\RewardGrantState;
use Forwext\Core\Reward\RewardMaintenanceTasks;
use Forwext\Core\Scheduler\SchedulerRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RewardPromotionSafetyTest extends TestCase
{
    public function testPromotionCanRevokeOnlyWhenPolicyIsEnabled(): void
    {
        $user=UserId::generate();
        $repo=new MemoryPromotionRepository();
        $metrics=new MutablePromotionMetric(10);
        $gateway=new RecordingRewardGateway();

        $reversible=$this->promotion('reversible',true);
        $sticky=$this->promotion('sticky',false);
        $repo->save($reversible);
        $repo->save($sticky);

        $service=new PromotionService(
            $repo,$metrics,$gateway,$this->authorizer([]),new RewardPromotionAudit()
        );

        $service->evaluateUser($user,$this->at('2026-09-19 19:00:00'));
        self::assertCount(2,$gateway->grantRequests);

        $metrics->value=0;
        $service->evaluateUser($user,$this->at('2026-09-19 19:05:00'));

        self::assertSame(
            ['promotion:'.$reversible->promotionId->value().':'.$user->value()],
            $gateway->revocations,
            'Only the opt-in promotion may revoke its own source entitlement.'
        );
    }

    public function testRoleRewardRejectsProtectedStaffOrSystemTarget(): void
    {
        $provider=new DatabaseRoleRewardProvider(new RewardSafetyDatabase(
            one:['kind'=>'staff','is_protected'=>1]
        ));

        $this->expectException(RuntimeException::class);
        $provider->apply(
            UserId::generate(),EntityId::fromString(str_repeat('a',32)),1,$this->at('2026-09-19 19:00:00')
        );
    }

    public function testSecondaryGroupRewardRejectsSystemTarget(): void
    {
        $provider=new DatabaseSecondaryGroupRewardProvider(new RewardSafetyDatabase(
            one:['is_system'=>1]
        ));

        $this->expectException(RuntimeException::class);
        $provider->apply(
            UserId::generate(),EntityId::fromString(str_repeat('b',32)),1,$this->at('2026-09-19 19:00:00')
        );
    }

    public function testSecondaryGroupRewardDoesNotDuplicatePrimaryGroup(): void
    {
        $target=EntityId::fromString(str_repeat('c',32));
        $database=new RewardSafetyDatabase(
            one:['is_system'=>0],
            values:[$target->value()],
        );
        $provider=new DatabaseSecondaryGroupRewardProvider($database);

        $result=$provider->apply(UserId::generate(),$target,1,$this->at('2026-09-19 19:00:00'));

        self::assertFalse($result->createdAssignment);
        self::assertSame(0,$database->executeCount);
    }

    public function testMaintenanceTasksRemainBounded(): void
    {
        $promotionRegistry=new SchedulerRegistry();
        PromotionMaintenanceTasks::register($promotionRegistry);
        self::assertSame('promotion.evaluate',$promotionRegistry->all()[0]->jobType);
        self::assertSame('{"limit":200}',$promotionRegistry->all()[0]->payload);

        $rewardRegistry=new SchedulerRegistry();
        RewardMaintenanceTasks::register($rewardRegistry);
        self::assertSame('reward.retry',$rewardRegistry->all()[0]->jobType);
        self::assertSame('{"limit":100}',$rewardRegistry->all()[0]->payload);
    }

    private function promotion(string $key,bool $revoke):PromotionDefinition
    {
        $at=$this->at('2026-09-19 18:00:00');
        return new PromotionDefinition(
            PromotionDefinition::generateId(),$key,ucfirst($key),true,100,
            PromotionRuleType::VisiblePostCount,5,'member.reward',1,$revoke,$at,$at
        );
    }

    /** @param array<string,array<string,bool>> $permissions */
    private function authorizer(array $permissions):PermissionAuthorizer
    {
        return new PermissionAuthorizer(
            new PermissionEngine(new RewardPromotionPermissionRules($permissions)),
            new RewardPromotionAssignments(array_keys($permissions)),
        );
    }

    private function at(string $value):DateTimeImmutable
    {
        return new DateTimeImmutable($value,new DateTimeZone('UTC'));
    }
}

final class MutablePromotionMetric implements PromotionMetricProvider
{
    public function __construct(public int $value){}
    public function metric(EntityId $userId,PromotionRuleType $type,DateTimeImmutable $now):int{return $this->value;}
}

final class MemoryPromotionRepository implements PromotionRepository
{
    /** @var array<string,PromotionDefinition> */
    private array $items=[];
    private ?EntityId $cursor=null;

    public function find(EntityId $promotionId):?PromotionDefinition{return $this->items[$promotionId->value()]??null;}
    public function definitions(bool $activeOnly=false,int $limit=500):array
    {
        return array_slice(array_values(array_filter(
            $this->items,static fn(PromotionDefinition $d):bool=>!$activeOnly||$d->active
        )),0,$limit);
    }
    public function save(PromotionDefinition $definition):void{$this->items[$definition->promotionId->value()]=$definition;}
    public function evaluationCursor():?EntityId{return $this->cursor;}
    public function setEvaluationCursor(?EntityId $userId):void{$this->cursor=$userId;}
    public function evaluationCandidates(?EntityId $afterUserId,int $limit):array{return [];}
}

final class RecordingRewardGateway implements RewardGrantGateway
{
    /** @var list<RewardGrantRequest> */
    public array $grantRequests=[];
    /** @var list<string> */
    public array $revocations=[];

    public function grant(RewardGrantRequest $request,DateTimeImmutable $now):RewardGrant
    {
        $this->grantRequests[]=$request;
        return new RewardGrant(
            RewardGrant::generateId(),$request->recipientUserId,$request->sourceType,$request->sourceId,
            $request->rewardKey,$request->units,RewardGrantState::Pending,null,null,null,null,$now
        );
    }

    public function fulfillBindings(
        string $sourceType,EntityId $sourceDefinitionId,string $sourceEventId,
        EntityId $recipientUserId,DateTimeImmutable $now
    ):array{return [];}

    public function revokeSource(
        string $sourceType,string $sourceEventId,EntityId $recipientUserId,DateTimeImmutable $now
    ):array{
        $this->revocations[]=$sourceType.':'.$sourceEventId.':'.$recipientUserId->value();
        return [];
    }
}

final class RewardSafetyDatabase implements TransactionalQueryExecutor
{
    public int $executeCount=0;
    private int $depth=0;
    /** @param array<string,mixed>|null $one @param list<mixed> $values */
    public function __construct(private ?array $one=null,private array $values=[]){}
    public function execute(CompiledQuery $query):int{++$this->executeCount;return 1;}
    public function fetchOne(CompiledQuery $query):?array{return $this->one;}
    public function fetchAll(CompiledQuery $query):array{return [];}
    public function fetchValue(CompiledQuery $query):mixed{return array_shift($this->values)??0;}
    public function inTransaction():bool{return $this->depth>0;}
    public function transaction(Closure $callback):mixed
    {
        ++$this->depth;
        try{return $callback($this);}finally{--$this->depth;}
    }
}

final class RewardPromotionPermissionRules implements PermissionRuleRepository
{
    /** @param array<string,array<string,bool>> $permissions */
    public function __construct(private array $permissions){}
    public function definition(PermissionKey $key):?PermissionDefinition{return new PermissionDefinition($key,PermissionValueType::Flag);}
    public function rules(PermissionKey $key,UserAccessAssignment $assignment,?EntityId $nodeId):array
    {
        $allow=$this->permissions[$assignment->userId()->value()][$key->value()]??false;
        return [new PermissionRule(
            PermissionSubjectType::User,$assignment->userId(),
            $allow?PermissionEffect::Allow:PermissionEffect::Deny
        )];
    }
}

final class RewardPromotionAssignments implements UserAccessAssignmentProvider
{
    /** @var array<string,true> */
    private array $ids;
    /** @param list<string> $ids */
    public function __construct(array $ids){$this->ids=array_fill_keys($ids,true);}
    public function find(EntityId $userId):?UserAccessAssignment
    {
        return isset($this->ids[$userId->value()])
            ?new UserAccessAssignment($userId,EntityId::fromString(str_repeat('d',32)))
            :null;
    }
}

final class RewardPromotionAudit implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events=[];
    public function append(AuditEvent $event):void{$this->events[]=$event;}
    public function mutate(AuditEvent $event,callable $mutation):mixed
    {
        $result=$mutation();$this->events[]=$event;return $result;
    }
}
