<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Support\Reporting;

use DateTimeImmutable;
use DateTimeZone;
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
use Forwext\Core\Support\Reporting\SupportAuditEntry;
use Forwext\Core\Support\Reporting\SupportCategoryMetric;
use Forwext\Core\Support\Reporting\SupportDashboardSummary;
use Forwext\Core\Support\Reporting\SupportReportingRepository;
use Forwext\Core\Support\Reporting\SupportReportingService;
use Forwext\Core\Support\Ticket\SupportCategory;
use Forwext\Core\Support\Ticket\SupportSlaMetadata;
use Forwext\Core\Support\Ticket\SupportTicket;
use Forwext\Core\Support\Ticket\SupportTicketPriority;
use Forwext\Core\Support\Ticket\SupportTicketRepository;
use Forwext\Core\Support\Ticket\SupportTicketStatus;
use PHPUnit\Framework\TestCase;

final class SupportReportingServiceTest extends TestCase
{
    public function testMyTicketsReturnsOnlyRequesterScopedRepositoryRows(): void
    {
        $actor=$this->id('1');
        $other=$this->id('2');
        $repo=new ReportingTicketRepository([
            $this->ticket('a',$actor),
            $this->ticket('b',$other),
        ]);
        $service=$this->service($actor,[$actor->value()=>['support.ticket.view_own']],$repo,new ReportingRepository());

        $tickets=$service->myTickets();

        self::assertCount(1,$tickets);
        self::assertSame($actor->value(),$tickets[0]->requesterUserId?->value());
    }

    public function testStaffDashboardRequiresReportPermissionAndHidesAuditWithoutAuditPermission(): void
    {
        $staff=$this->id('1');
        $repo=new ReportingTicketRepository([$this->ticket('a',$this->id('2'))]);
        $reporting=new ReportingRepository();
        $service=$this->service(
            $staff,
            [$staff->value()=>['support.ticket.view_all','support.report.view']],
            $repo,
            $reporting,
        );

        $dashboard=$service->staffDashboard($this->now());

        self::assertSame(1,$dashboard->summary->total);
        self::assertCount(1,$dashboard->queue);
        self::assertSame([], $dashboard->audit);
        self::assertSame(0,$reporting->auditReads);
    }

    public function testAuditRowsRequireDedicatedSupportAuditPermission(): void
    {
        $staff=$this->id('1');
        $repo=new ReportingTicketRepository([]);
        $reporting=new ReportingRepository();
        $service=$this->service(
            $staff,
            [$staff->value()=>['support.ticket.view_all','support.report.view','support.audit.view']],
            $repo,
            $reporting,
        );

        $dashboard=$service->staffDashboard($this->now());

        self::assertCount(1,$dashboard->audit);
        self::assertSame(1,$reporting->auditReads);
    }

    /** @param array<string,list<string>> $permissions */
    private function service(EntityId $actor,array $permissions,ReportingTicketRepository $tickets,ReportingRepository $reporting):SupportReportingService
    {
        $authorizer=new PermissionAuthorizer(
            new PermissionEngine(new ReportingPermissionRepository($permissions)),
            new ReportingAssignmentProvider(array_keys($permissions)),
        );
        return new SupportReportingService($tickets,$reporting,new PermissionGate($authorizer,$actor));
    }

    private function ticket(string $seed,EntityId $requester):SupportTicket
    {
        $now=$this->now();
        return new SupportTicket(
            $this->id($seed),'general',$requester,null,'Ticket '.$seed,
            SupportTicketPriority::Normal,SupportTicketStatus::Open,
            new SupportSlaMetadata(null,null),null,$now,$now,1,
        );
    }
    private function id(string $seed):EntityId{return EntityId::fromString(str_repeat($seed,32));}
    private function now():DateTimeImmutable{return new DateTimeImmutable('2026-09-18 18:00:00',new DateTimeZone('UTC'));}
}

final class ReportingTicketRepository implements SupportTicketRepository
{
    /** @param list<SupportTicket> $tickets */
    public function __construct(private array $tickets){}
    public function activeCategories():array{return [];}
    public function category(string $key):?SupportCategory{return null;}
    public function saveCategory(SupportCategory $category):void{}
    public function create(SupportTicket $ticket):void{}
    public function find(EntityId $ticketId):?SupportTicket{foreach($this->tickets as $t)if($t->ticketId->equals($ticketId))return $t;return null;}
    public function forRequester(EntityId $requesterUserId,int $limit=50):array
    {
        return array_slice(array_values(array_filter($this->tickets,static fn(SupportTicket $t):bool=>$t->requesterUserId?->equals($requesterUserId)??false)),0,$limit);
    }
    public function activeQueue(int $limit=100):array{return array_slice(array_values(array_filter($this->tickets,static fn(SupportTicket $t):bool=>$t->status->isActive())),0,$limit);}
    public function assign(EntityId $ticketId,?EntityId $assignedUserId,int $expectedVersion,DateTimeImmutable $now):SupportTicket{throw new \LogicException();}
    public function changePriority(EntityId $ticketId,SupportTicketPriority $priority,int $expectedVersion,DateTimeImmutable $now):SupportTicket{throw new \LogicException();}
    public function changeStatus(EntityId $ticketId,SupportTicketStatus $status,SupportSlaMetadata $sla,?DateTimeImmutable $closedAt,int $expectedVersion,DateTimeImmutable $now):SupportTicket{throw new \LogicException();}
    public function markFirstResponse(EntityId $ticketId,DateTimeImmutable $firstRespondedAt,int $expectedVersion,DateTimeImmutable $now):SupportTicket{throw new \LogicException();}
}

final class ReportingRepository implements SupportReportingRepository
{
    public int $auditReads=0;
    public function summary(DateTimeImmutable $now):SupportDashboardSummary{return new SupportDashboardSummary(1,1,0,0,0,0,120.0,3600.0);}
    public function categoryMetrics(DateTimeImmutable $now,int $limit=100):array{return [new SupportCategoryMetric('general','General',1,1,0,0,0,120.0,3600.0)];}
    public function recentAudit(int $limit=50):array
    {
        $this->auditReads++;
        $now=new DateTimeImmutable('2026-09-18 18:00:00',new DateTimeZone('UTC'));
        return [new SupportAuditEntry(EntityId::fromString(str_repeat('a',32)),EntityId::fromString(str_repeat('1',32)),'support.ticket.status','support.ticket',str_repeat('b',32),'req-1',$now)];
    }
}

final readonly class ReportingAssignmentProvider implements UserAccessAssignmentProvider
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

final readonly class ReportingPermissionRepository implements PermissionRuleRepository
{
    /** @param array<string,list<string>> $permissions */
    public function __construct(private array $permissions){}
    public function definition(PermissionKey $key):?PermissionDefinition{return new PermissionDefinition($key,PermissionValueType::Flag);}
    public function rules(PermissionKey $key,UserAccessAssignment $assignment,?EntityId $nodeId):array
    {
        if($nodeId!==null||!in_array($key->value(),$this->permissions[$assignment->userId()->value()]??[],true))return [];
        return [new PermissionRule(PermissionSubjectType::User,$assignment->userId(),PermissionEffect::Allow)];
    }
}
