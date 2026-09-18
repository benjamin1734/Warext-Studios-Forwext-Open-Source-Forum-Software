<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Support;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Support\MyTicketsHtml;
use Forwext\App\Web\Support\SupportStaffDashboardHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Support\Reporting\SupportAuditEntry;
use Forwext\Core\Support\Reporting\SupportCategoryMetric;
use Forwext\Core\Support\Reporting\SupportDashboardSummary;
use Forwext\Core\Support\Reporting\SupportStaffDashboard;
use Forwext\Core\Support\Ticket\SupportSlaMetadata;
use Forwext\Core\Support\Ticket\SupportTicket;
use Forwext\Core\Support\Ticket\SupportTicketPriority;
use Forwext\Core\Support\Ticket\SupportTicketStatus;
use PHPUnit\Framework\TestCase;

final class SupportReportingHtmlTest extends TestCase
{
    public function testMyTicketsEscapesTicketSubject():void
    {
        $ticket=$this->ticket('<script>alert(1)</script>');
        $html=MyTicketsHtml::page([$ticket],new BasePath('/community'));

        self::assertStringNotContainsString('<script>alert(1)</script>',$html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$html);
        self::assertStringContainsString('/community/support/tickets/'.$ticket->ticketId->value(),$html);
    }

    public function testStaffDashboardEscapesCategoryAndAuditFields():void
    {
        $now=$this->now();
        $dashboard=new SupportStaffDashboard(
            new SupportDashboardSummary(1,1,0,0,0,0,60.0,120.0),
            [$this->ticket('<img src=x onerror=alert(1)>')],
            [new SupportCategoryMetric('general','<b>Unsafe</b>',1,1,0,0,0,60.0,120.0)],
            [new SupportAuditEntry(
                EntityId::fromString(str_repeat('b',32)),
                EntityId::fromString(str_repeat('2',32)),
                'support.ticket.status',
                'support.ticket',
                '<bad>',
                'req-1',
                $now,
            )],
        );

        $html=SupportStaffDashboardHtml::page($dashboard,new BasePath('/community'));

        self::assertStringNotContainsString('<b>Unsafe</b>',$html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>',$html);
        self::assertStringContainsString('&lt;b&gt;Unsafe&lt;/b&gt;',$html);
        self::assertStringContainsString('&lt;bad&gt;',$html);
    }

    private function ticket(string $subject):SupportTicket
    {
        $now=$this->now();
        return new SupportTicket(
            EntityId::fromString(str_repeat('a',32)),
            'general',
            EntityId::fromString(str_repeat('1',32)),
            null,
            $subject,
            SupportTicketPriority::Normal,
            SupportTicketStatus::Open,
            new SupportSlaMetadata(null,null),
            null,
            $now,
            $now,
            1,
        );
    }

    private function now():DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-18 18:00:00',new DateTimeZone('UTC'));
    }
}
