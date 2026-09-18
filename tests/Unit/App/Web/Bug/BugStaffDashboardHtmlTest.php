<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Bug;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Bug\BugStaffDashboardHtml;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportCategory;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Bug\Staff\BugAuditEntry;
use Forwext\Core\Bug\Staff\BugCategoryMetric;
use Forwext\Core\Bug\Staff\BugStaffDashboard;
use Forwext\Core\Bug\Staff\BugStaffFilter;
use Forwext\Core\Bug\Staff\BugStaffSummary;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class BugStaffDashboardHtmlTest extends TestCase
{
    public function testDashboardEscapesReportContentAndExposesFilterExportAndAudit(): void
    {
        $at=new DateTimeImmutable('2026-09-18 20:00:00',new DateTimeZone('UTC'));
        $staff=EntityId::fromString(str_repeat('1',32));
        $reporter=EntityId::fromString(str_repeat('2',32));
        $report=new BugReport(
            EntityId::fromString(str_repeat('a',32)),
            'frontend',
            $reporter,
            $staff,
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            BugReportSeverity::High,
            BugReportStatus::InReview,
            null,
            $at,
            $at,
            2,
        );
        $dashboard=new BugStaffDashboard(
            new BugStaffFilter('editor',BugReportStatus::InReview,BugReportSeverity::High,'frontend',$staff,false,50,0),
            new BugStaffSummary(10,2,3,3,1,1,1),
            [$report],
            [new BugReportCategory('frontend','Arayüz','',BugReportSeverity::Medium,10,true)],
            [new BugCategoryMetric('frontend','Arayüz',10,5,5,1)],
            [new BugAuditEntry(
                EntityId::fromString(str_repeat('b',32)),
                $staff,
                'bug.report.status',
                'bug.report',
                $report->reportId->value(),
                'request-12345678',
                $at,
            )],
            true,
            true,
        );

        $html=BugStaffDashboardHtml::page(
            $dashboard,
            new BasePath('/community'),
            [$staff->value()=>'<Admin>',$reporter->value()=>'<Reporter>'],
            'Admin',
        );

        self::assertStringContainsString('/community/bugs/staff/export.csv',$html);
        self::assertStringContainsString('name="q"',$html);
        self::assertStringContainsString('Bug audit',$html);
        self::assertStringNotContainsString('<script>alert(1)</script>',$html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>',$html);
        self::assertStringNotContainsString('<Admin>',$html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$html);
    }
}
