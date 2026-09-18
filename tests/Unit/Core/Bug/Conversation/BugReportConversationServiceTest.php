<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Bug\Conversation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Bug\Conversation\BugReportConversationRepository;
use Forwext\Core\Bug\Conversation\BugReportConversationService;
use Forwext\Core\Bug\Conversation\BugReportMessage;
use Forwext\Core\Bug\Conversation\BugReportNotifier;
use Forwext\Core\Bug\Report\BugHistoryVisibility;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportCategory;
use Forwext\Core\Bug\Report\BugReportHistoryEntry;
use Forwext\Core\Bug\Report\BugReportOperationException;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
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

final class BugReportConversationServiceTest extends TestCase
{
    public function testReporterCanAddInformationAndAssignedStaffIsNotified():void
    {
        $reporter=$this->id('1');
        $assignee=$this->id('2');
        $repo=new BugConversationReportRepository();
        $report=$this->report($reporter,$assignee);
        $repo->reports[$report->reportId->value()]=$report;
        $conversation=new BugConversationMemoryRepository();
        $notifier=new BugConversationNotifier();
        $service=$this->service(
            $reporter,
            [
                $reporter->value()=>['bug.report.view_own','bug.report.reply_own'],
                $assignee->value()=>['bug.report.view_all'],
            ],
            $repo,$conversation,$notifier,
        );

        $message=$service->addReporterInfo(
            $report->reportId,
            'Additional reproduction detail.',
            $this->time('2026-09-18 20:30:00.000000'),
        );

        self::assertSame('reporter',$message->authorRole->value);
        self::assertCount(1,$conversation->messages);
        self::assertSame(1,$notifier->reporterReplies);
    }

    public function testReporterCannotAddInformationToAnotherUsersReportById():void
    {
        $actor=$this->id('1');
        $repo=new BugConversationReportRepository();
        $report=$this->report($this->id('2'));
        $repo->reports[$report->reportId->value()]=$report;
        $service=$this->service(
            $actor,
            [$actor->value()=>['bug.report.view_own','bug.report.reply_own']],
            $repo,new BugConversationMemoryRepository(),new BugConversationNotifier(),
        );

        $this->expectException(PermissionDeniedException::class);
        $service->addReporterInfo($report->reportId,'Injected follow-up.');
    }

    public function testStaffReplyAndStatusChangeNotifyReporter():void
    {
        $staff=$this->id('1');
        $reporter=$this->id('2');
        $repo=new BugConversationReportRepository();
        $report=$this->report($reporter);
        $repo->reports[$report->reportId->value()]=$report;
        $conversation=new BugConversationMemoryRepository();
        $notifier=new BugConversationNotifier();
        $service=$this->service(
            $staff,
            [
                $staff->value()=>['bug.report.view_all','bug.report.reply_all','bug.report.manage'],
                $reporter->value()=>['bug.report.view_own'],
            ],
            $repo,$conversation,$notifier,
        );

        $service->staffReply(
            $report->reportId,
            'We reproduced this issue.',
            $this->time('2026-09-18 20:35:00.000000'),
        );
        $changed=$service->changeStatus(
            $report->reportId,
            BugReportStatus::InReview,
            $this->time('2026-09-18 20:36:00.000000'),
        );

        self::assertSame(BugReportStatus::InReview,$changed->status);
        self::assertSame(1,$notifier->staffReplies);
        self::assertSame(1,$notifier->statusChanges);
        self::assertCount(1,$conversation->messages);
        self::assertCount(1,$repo->history);
    }

    private function service(
        EntityId $actor,
        array $permissions,
        BugConversationReportRepository $repo,
        BugConversationMemoryRepository $conversation,
        BugConversationNotifier $notifier,
    ):BugReportConversationService {
        $database=new BugConversationDatabase();
        $authorizer=new PermissionAuthorizer(
            new PermissionEngine(new BugConversationPermissionRepository($permissions)),
            new BugConversationAssignmentProvider(array_keys($permissions)),
        );
        $gate=new PermissionGate($authorizer,$actor);
        return new BugReportConversationService(
            $database,
            new BugReportService($database,$repo,$gate,$authorizer),
            $conversation,
            $gate,
            $notifier,
        );
    }

    private function report(EntityId $reporter,?EntityId $assignee=null):BugReport
    {
        $at=$this->time('2026-09-18 20:00:00.000000');
        return new BugReport(
            $this->id('a'),'general',$reporter,$assignee,'Stored bug','Stored summary.',
            BugReportSeverity::Medium,BugReportStatus::New,null,$at,$at,1,
        );
    }

    private function id(string $seed):EntityId{return EntityId::fromString(str_repeat($seed,32));}

    private function time(string $value):DateTimeImmutable
    {
        $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$value,new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class,$time);
        return $time;
    }
}

final class BugConversationMemoryRepository implements BugReportConversationRepository
{
    /** @var list<BugReportMessage> */
    public array $messages=[];
    public function append(BugReportMessage $message):void{$this->messages[]=$message;}
    public function messages(EntityId $reportId,int $limit=200):array
    {
        return array_slice(array_values(array_filter(
            $this->messages,
            static fn(BugReportMessage $message):bool=>$message->reportId->equals($reportId),
        )),0,$limit);
    }
}

final class BugConversationNotifier implements BugReportNotifier
{
    public int $staffReplies=0;
    public int $reporterReplies=0;
    public int $statusChanges=0;
    public function staffReply(BugReport $report,BugReportMessage $message):void{$this->staffReplies++;}
    public function reporterReply(BugReport $report,BugReportMessage $message):void{$this->reporterReplies++;}
    public function statusChanged(BugReport $report):void{$this->statusChanges++;}
}

final class BugConversationReportRepository implements BugReportRepository
{
    /** @var array<string,BugReport> */
    public array $reports=[];
    /** @var list<BugReportHistoryEntry> */
    public array $history=[];
    public function activeCategories():array{return [new BugReportCategory('general','General','',BugReportSeverity::Medium,10,true)];}
    public function category(string $key):?BugReportCategory{return $key==='general'?$this->activeCategories()[0]:null;}
    public function saveCategory(BugReportCategory $category):void{}
    public function create(BugReport $report):void{$this->reports[$report->reportId->value()]=$report;}
    public function find(EntityId $reportId):?BugReport{return $this->reports[$reportId->value()]??null;}
    public function forReporter(EntityId $reporterUserId,int $limit=50):array
    {
        return array_slice(array_values(array_filter(
            $this->reports,
            static fn(BugReport $report):bool=>$report->reporterUserId?->equals($reporterUserId)??false,
        )),0,$limit);
    }
    public function assign(EntityId $reportId,?EntityId $assignedUserId,int $expectedVersion,DateTimeImmutable $now):BugReport{throw new \LogicException();}
    public function changeStatus(EntityId $reportId,BugReportStatus $status,?DateTimeImmutable $finalizedAt,int $expectedVersion,DateTimeImmutable $now):BugReport
    {
        $current=$this->versioned($reportId,$expectedVersion);
        $updated=new BugReport(
            $current->reportId,$current->categoryKey,$current->reporterUserId,$current->assignedUserId,
            $current->title,$current->summary,$current->severity,$status,$finalizedAt,
            $current->createdAt,$now,$current->version+1,
        );
        $this->reports[$reportId->value()]=$updated;
        return $updated;
    }
    public function changeSeverity(EntityId $reportId,BugReportSeverity $severity,int $expectedVersion,DateTimeImmutable $now):BugReport{throw new \LogicException();}
    public function changeCategory(EntityId $reportId,string $categoryKey,int $expectedVersion,DateTimeImmutable $now):BugReport{throw new \LogicException();}
    public function appendHistory(BugReportHistoryEntry $entry):void{$this->history[]=$entry;}
    public function history(EntityId $reportId,bool $includeStaff,int $limit=200):array
    {
        return array_slice(array_values(array_filter(
            $this->history,
            static fn(BugReportHistoryEntry $entry):bool=>$entry->reportId->equals($reportId)
                &&($includeStaff||$entry->visibility===BugHistoryVisibility::Public),
        )),0,$limit);
    }
    private function versioned(EntityId $reportId,int $version):BugReport
    {
        $report=$this->find($reportId)??throw new BugReportOperationException('Missing report.');
        if($report->version!==$version)throw new BugReportOperationException('Stale report.');
        return $report;
    }
}

final class BugConversationDatabase implements TransactionalQueryExecutor
{
    private bool $inside=false;
    public function execute(CompiledQuery $query):int{return 1;}
    public function fetchOne(CompiledQuery $query):?array{return null;}
    public function fetchAll(CompiledQuery $query):array{return [];}
    public function fetchValue(CompiledQuery $query):mixed{return null;}
    public function inTransaction():bool{return $this->inside;}
    public function transaction(Closure $callback):mixed
    {
        $before=$this->inside;$this->inside=true;
        try{return $callback($this);}finally{$this->inside=$before;}
    }
}

final readonly class BugConversationAssignmentProvider implements UserAccessAssignmentProvider
{
    /** @param list<string> $ids */
    public function __construct(private array $ids){}
    public function find(EntityId $userId):?UserAccessAssignment
    {
        return in_array($userId->value(),$this->ids,true)
            ? new UserAccessAssignment($userId,EntityId::fromString(str_repeat('f',32)))
            : null;
    }
}

final readonly class BugConversationPermissionRepository implements PermissionRuleRepository
{
    /** @param array<string,list<string>> $permissions */
    public function __construct(private array $permissions){}
    public function definition(PermissionKey $key):?PermissionDefinition
    {
        return new PermissionDefinition($key,PermissionValueType::Flag);
    }
    public function rules(PermissionKey $key,UserAccessAssignment $assignment,?EntityId $nodeId):array
    {
        if($nodeId!==null||!in_array($key->value(),$this->permissions[$assignment->userId()->value()]??[],true)){
            return [];
        }
        return [new PermissionRule(PermissionSubjectType::User,$assignment->userId(),PermissionEffect::Allow)];
    }
}
