<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Bug\Staff;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Bug\Conversation\BugReportMessage;
use Forwext\Core\Bug\Conversation\BugReportNotifier;
use Forwext\Core\Bug\Report\BugHistoryVisibility;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportCategory;
use Forwext\Core\Bug\Report\BugReportHistoryEntry;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Bug\Staff\BugAuditEntry;
use Forwext\Core\Bug\Staff\BugCategoryMetric;
use Forwext\Core\Bug\Staff\BugDuplicateLink;
use Forwext\Core\Bug\Staff\BugStaffFilter;
use Forwext\Core\Bug\Staff\BugStaffRepository;
use Forwext\Core\Bug\Staff\BugStaffService;
use Forwext\Core\Bug\Staff\BugStaffSummary;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class BugStaffServiceTest extends TestCase
{
    public function testExplicitDuplicateLinkChangesStatusPersistsRelationAuditsAndNotifies(): void
    {
        $database=new StaffTransactionDatabase();
        $staff=EntityId::fromString(str_repeat('1',32));
        $reporter=EntityId::fromString(str_repeat('2',32));
        $source=$this->report('a',$reporter,BugReportStatus::New);
        $canonical=$this->report('b',$reporter,BugReportStatus::InReview);

        $reports=new StaffReportRepository();
        $reports->reports[$source->reportId->value()]=$source;
        $reports->reports[$canonical->reportId->value()]=$canonical;
        $staffRepository=new StaffRepository([$source,$canonical]);
        $authorizer=$this->authorizer($staff,['bug.report.view_all','bug.report.manage']);
        $audit=new StaffAuditRecorder($database);
        $notifier=new StaffNotifier();
        $service=new BugStaffService(
            $database,
            new BugReportService(
                $database,
                $reports,
                new PermissionGate($authorizer,$staff),
                $authorizer,
            ),
            $staffRepository,
            new PermissionGate($authorizer,$staff),
            $audit,
            AuditRequestId::fromString('request-12345678'),
            $notifier,
        );

        $link=$service->linkDuplicate(
            $source->reportId,
            $canonical->reportId,
            $this->time('2026-09-18 21:00:00.000000'),
        );

        self::assertSame($canonical->reportId->value(),$link->canonicalReportId->value());
        self::assertSame(BugReportStatus::Duplicate,$reports->find($source->reportId)?->status);
        self::assertSame($canonical->reportId->value(),$staffRepository->duplicateLink($source->reportId)?->canonicalReportId->value());
        self::assertCount(1,$audit->events);
        self::assertSame(AuditScope::Bug,$audit->events[0]->scope);
        self::assertSame('bug.report.duplicate.link',$audit->events[0]->action->value());
        self::assertTrue($audit->insideTransaction[0]);
        self::assertSame(1,$notifier->statusChanged);
    }

    private function authorizer(EntityId $actor,array $permissions):PermissionAuthorizer
    {
        return new PermissionAuthorizer(
            new PermissionEngine(new StaffPermissionRepository($actor,$permissions)),
            new StaffAssignmentProvider($actor),
        );
    }

    private function report(string $seed,EntityId $reporter,BugReportStatus $status):BugReport
    {
        $at=$this->time('2026-09-18 20:00:00.000000');
        $finalized=$status->isTerminal()?$at:null;
        return new BugReport(
            EntityId::fromString(str_repeat($seed,32)),
            'general',
            $reporter,
            null,
            'Stored bug '.$seed,
            'Stored bug summary '.$seed,
            BugReportSeverity::Medium,
            $status,
            $finalized,
            $at,
            $at,
            1,
        );
    }

    private function time(string $value):DateTimeImmutable
    {
        $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$value,new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class,$time);
        return $time;
    }
}

final class StaffTransactionDatabase implements TransactionalQueryExecutor
{
    private bool $inside=false;
    public function execute(CompiledQuery $query):int{return 1;}
    public function fetchOne(CompiledQuery $query):?array{return null;}
    public function fetchAll(CompiledQuery $query):array{return [];}
    public function fetchValue(CompiledQuery $query):mixed{return null;}
    public function inTransaction():bool{return $this->inside;}
    public function transaction(Closure $callback):mixed
    {
        $before=$this->inside;
        $this->inside=true;
        try{return $callback($this);}finally{$this->inside=$before;}
    }
}

final class StaffReportRepository implements BugReportRepository
{
    /** @var array<string,BugReport> */
    public array $reports=[];
    /** @var list<BugReportHistoryEntry> */
    public array $history=[];

    public function activeCategories():array
    {
        return [new BugReportCategory('general','Genel','',BugReportSeverity::Medium,10,true)];
    }
    public function category(string $key):?BugReportCategory
    {
        return $key==='general'?new BugReportCategory('general','Genel','',BugReportSeverity::Medium,10,true):null;
    }
    public function saveCategory(BugReportCategory $category):void{}
    public function create(BugReport $report):void{$this->reports[$report->reportId->value()]=$report;}
    public function find(EntityId $reportId):?BugReport{return $this->reports[$reportId->value()]??null;}
    public function forReporter(EntityId $reporterUserId,int $limit=50):array{return [];}
    public function assign(EntityId $reportId,?EntityId $assignedUserId,int $expectedVersion,DateTimeImmutable $now):BugReport
    {
        $current=$this->required($reportId,$expectedVersion);
        return $this->store(new BugReport(
            $current->reportId,$current->categoryKey,$current->reporterUserId,$assignedUserId,
            $current->title,$current->summary,$current->severity,$current->status,$current->finalizedAt,
            $current->createdAt,$now,$current->version+1,
        ));
    }
    public function changeStatus(EntityId $reportId,BugReportStatus $status,?DateTimeImmutable $finalizedAt,int $expectedVersion,DateTimeImmutable $now):BugReport
    {
        $current=$this->required($reportId,$expectedVersion);
        return $this->store(new BugReport(
            $current->reportId,$current->categoryKey,$current->reporterUserId,$current->assignedUserId,
            $current->title,$current->summary,$current->severity,$status,$finalizedAt,
            $current->createdAt,$now,$current->version+1,
        ));
    }
    public function changeSeverity(EntityId $reportId,BugReportSeverity $severity,int $expectedVersion,DateTimeImmutable $now):BugReport
    {
        $current=$this->required($reportId,$expectedVersion);
        return $this->store(new BugReport(
            $current->reportId,$current->categoryKey,$current->reporterUserId,$current->assignedUserId,
            $current->title,$current->summary,$severity,$current->status,$current->finalizedAt,
            $current->createdAt,$now,$current->version+1,
        ));
    }
    public function changeCategory(EntityId $reportId,string $categoryKey,int $expectedVersion,DateTimeImmutable $now):BugReport
    {
        $current=$this->required($reportId,$expectedVersion);
        return $this->store(new BugReport(
            $current->reportId,$categoryKey,$current->reporterUserId,$current->assignedUserId,
            $current->title,$current->summary,$current->severity,$current->status,$current->finalizedAt,
            $current->createdAt,$now,$current->version+1,
        ));
    }
    public function appendHistory(BugReportHistoryEntry $entry):void{$this->history[]=$entry;}
    public function history(EntityId $reportId,bool $includeStaff,int $limit=200):array
    {
        return array_values(array_filter(
            $this->history,
            static fn(BugReportHistoryEntry $entry):bool=>$entry->reportId->equals($reportId)
                &&($includeStaff||$entry->visibility===BugHistoryVisibility::Public),
        ));
    }
    private function required(EntityId $id,int $version):BugReport
    {
        $report=$this->find($id)??throw new \RuntimeException('Missing report.');
        if($report->version!==$version)throw new \RuntimeException('Stale report.');
        return $report;
    }
    private function store(BugReport $report):BugReport
    {
        $this->reports[$report->reportId->value()]=$report;
        return $report;
    }
}

final class StaffRepository implements BugStaffRepository
{
    /** @var array<string,BugDuplicateLink> */
    public array $links=[];
    /** @param list<BugReport> $reports */
    public function __construct(private array $reports){}
    public function search(BugStaffFilter $filter):array{return array_slice($this->reports,$filter->offset,$filter->limit);}
    public function summary():BugStaffSummary{return new BugStaffSummary(count($this->reports),0,0,0,0,0,0);}
    public function categoryMetrics(int $limit=100):array{return [new BugCategoryMetric('general','Genel',count($this->reports),count($this->reports),0,0)];}
    public function duplicateCandidates(BugReport $source,int $limit=100):array{return $this->reports;}
    public function duplicateLink(EntityId $duplicateReportId):?BugDuplicateLink{return $this->links[$duplicateReportId->value()]??null;}
    public function saveDuplicateLink(BugDuplicateLink $link):void{$this->links[$link->duplicateReportId->value()]=$link;}
    public function deleteDuplicateLink(EntityId $duplicateReportId):bool
    {
        $key=$duplicateReportId->value();
        if(!isset($this->links[$key]))return false;
        unset($this->links[$key]);
        return true;
    }
    public function recentAudit(int $limit=50):array{return [];}
}

final class StaffAuditRecorder implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events=[];
    /** @var list<bool> */
    public array $insideTransaction=[];
    public function __construct(private StaffTransactionDatabase $database){}
    public function append(AuditEvent $event):void
    {
        $this->events[]=$event;
        $this->insideTransaction[]=$this->database->inTransaction();
    }
    public function mutate(AuditEvent $event,callable $mutation):mixed
    {
        $result=$mutation();
        $this->append($event);
        return $result;
    }
}

final class StaffNotifier implements BugReportNotifier
{
    public int $statusChanged=0;
    public function staffReply(BugReport $report,BugReportMessage $message):void{}
    public function reporterReply(BugReport $report,BugReportMessage $message):void{}
    public function statusChanged(BugReport $report):void{$this->statusChanged++;}
}

final readonly class StaffAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $actor){}
    public function find(EntityId $userId):?UserAccessAssignment
    {
        return $userId->equals($this->actor)
            ? new UserAccessAssignment($userId,EntityId::fromString(str_repeat('f',32)))
            : null;
    }
}

final readonly class StaffPermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $permissions */
    public function __construct(private EntityId $actor,private array $permissions){}
    public function definition(PermissionKey $key):?PermissionDefinition
    {
        return new PermissionDefinition($key,PermissionValueType::Flag);
    }
    public function rules(PermissionKey $key,UserAccessAssignment $assignment,?EntityId $nodeId):array
    {
        if($nodeId!==null||!$assignment->userId()->equals($this->actor)||!in_array($key->value(),$this->permissions,true)){
            return [];
        }
        return [new PermissionRule(PermissionSubjectType::User,$this->actor,PermissionEffect::Allow)];
    }
}
