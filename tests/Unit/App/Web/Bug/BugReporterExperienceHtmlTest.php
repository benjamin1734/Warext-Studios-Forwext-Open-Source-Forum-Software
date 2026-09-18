<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Bug;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Bug\BugReportDetailHtml;
use Forwext\App\Web\Bug\MyBugReportsHtml;
use Forwext\Core\Bug\Intake\BugReportIntake;
use Forwext\Core\Bug\Report\BugHistoryEventType;
use Forwext\Core\Bug\Report\BugHistoryVisibility;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportHistoryEntry;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class BugReporterExperienceHtmlTest extends TestCase
{
    public function testMyReportsAndDetailEscapeUserContentAndExposeReporterNavigation(): void
    {
        $reporter = EntityId::fromString(str_repeat('1', 32));
        $reportId = EntityId::fromString(str_repeat('a', 32));
        $at = $this->time('2026-09-18 20:00:00.000000');
        $report = new BugReport(
            $reportId,
            'general',
            $reporter,
            null,
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            BugReportSeverity::Medium,
            BugReportStatus::New,
            null,
            $at,
            $at,
            1,
        );
        $intake = new BugReportIntake(
            $reportId,
            '1. Open page',
            'Safe result',
            'Broken result',
            '/threads/' . str_repeat('b', 32),
            $at,
        );
        $history = [new BugReportHistoryEntry(
            EntityId::fromString(str_repeat('c', 32)),
            $reportId,
            EntityId::fromString(str_repeat('d', 32)),
            BugHistoryEventType::StaffResponse,
            BugHistoryVisibility::Public,
            ['body'=>'<svg onload=alert(1)>Please retry.</svg>'],
            $at,
        )];
        $basePath = new BasePath('/community');

        $list = MyBugReportsHtml::page([$report], $basePath);
        self::assertStringContainsString('/community/bugs/' . $reportId->value(), $list);
        self::assertStringNotContainsString('<script>alert(1)</script>', $list);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $list);

        $detail = BugReportDetailHtml::page(
            $report,
            $intake,
            [],
            $history,
            'csrf-token',
            $basePath,
            true,
            false,
        );

        self::assertStringContainsString('/community/bugs/my', $detail);
        self::assertStringContainsString('name="action" value="additional_info"', $detail);
        self::assertStringContainsString('name="_csrf" value="csrf-token"', $detail);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $detail);
        self::assertStringNotContainsString('<svg onload=alert(1)>Please retry.</svg>', $detail);
        self::assertStringContainsString('&lt;svg onload=alert(1)&gt;Please retry.&lt;/svg&gt;', $detail);
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}
