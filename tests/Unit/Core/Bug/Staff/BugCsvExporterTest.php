<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Bug\Staff;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Bug\Staff\BugAuditEntry;
use Forwext\Core\Bug\Staff\BugCategoryMetric;
use Forwext\Core\Bug\Staff\BugCsvExporter;
use Forwext\Core\Bug\Staff\BugDuplicateLink;
use Forwext\Core\Bug\Staff\BugStaffFilter;
use Forwext\Core\Bug\Staff\BugStaffRepository;
use Forwext\Core\Bug\Staff\BugStaffSummary;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class BugCsvExporterTest extends TestCase
{
    public function testExportNeutralizesSpreadsheetFormulaPrefixes():void
    {
        $at=new DateTimeImmutable('2026-09-18 20:00:00',new DateTimeZone('UTC'));
        $report=new BugReport(
            EntityId::fromString(str_repeat('a',32)),
            'general',
            EntityId::fromString(str_repeat('1',32)),
            null,
            '=HYPERLINK("https://example.invalid")',
            '+SUM(1,1)',
            BugReportSeverity::Medium,
            BugReportStatus::New,
            null,
            $at,
            $at,
            1,
        );
        $repository=new CsvStaffRepository();

        $csv=(new BugCsvExporter())->export([$report],$repository);

        self::assertStringContainsString("'=HYPERLINK",$csv);
        self::assertStringNotContainsString("\n=HYPERLINK",$csv);
    }
}

final class CsvStaffRepository implements BugStaffRepository
{
    public function search(BugStaffFilter $filter):array{return [];}
    public function summary():BugStaffSummary{return new BugStaffSummary(0,0,0,0,0,0,0);}
    public function categoryMetrics(int $limit=100):array{return [];}
    public function duplicateCandidates(BugReport $source,int $limit=100):array{return [];}
    public function duplicateLink(EntityId $duplicateReportId):?BugDuplicateLink{return null;}
    public function saveDuplicateLink(BugDuplicateLink $link):void{}
    public function deleteDuplicateLink(EntityId $duplicateReportId):bool{return false;}
    public function recentAudit(int $limit=50):array{return [];}
}
