<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Bug;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Bug\BugReportDetailCapabilities;
use Forwext\App\Web\Bug\BugReportDetailHtml;
use Forwext\App\Web\Bug\MyBugReportsHtml;
use Forwext\Core\Bug\Conversation\BugReportConversationView;
use Forwext\Core\Bug\Conversation\BugReportMessage;
use Forwext\Core\Bug\Conversation\BugReportMessageRole;
use Forwext\Core\Bug\Intake\BugReportIntake;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class BugReportDetailHtmlTest extends TestCase
{
    public function testDetailEscapesReporterContentAndProvidesFollowUpForm():void
    {
        $at=new DateTimeImmutable('2026-09-18T20:00:00+00:00');
        $reporter=EntityId::fromString(str_repeat('1',32));
        $report=new BugReport(
            EntityId::fromString(str_repeat('a',32)),
            'general',
            $reporter,
            null,
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(2)>',
            BugReportSeverity::Medium,
            BugReportStatus::New,
            null,
            $at,
            $at,
            1,
        );
        $message=new BugReportMessage(
            EntityId::fromString(str_repeat('b',32)),
            $report->reportId,
            $reporter,
            BugReportMessageRole::Reporter,
            '<svg onload=alert(3)>',
            $at,
        );
        $intake=new BugReportIntake(
            $report->reportId,
            '<b>steps</b>',
            '<i>expected</i>',
            '<iframe>actual</iframe>',
            '/threads/<unsafe>',
            $at,
        );

        $html=BugReportDetailHtml::page(
            new BugReportConversationView($report,[$message],[],false),
            $intake,
            [],
            'csrf-token',
            new BasePath('/community'),
            new BugReportDetailCapabilities(true,false),
            '<u>General</u>',
        );

        foreach([
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(2)>',
            '<svg onload=alert(3)>',
            '<iframe>actual</iframe>',
            '<u>General</u>',
        ] as $unsafe){
            self::assertStringNotContainsString($unsafe,$html);
        }
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$html);
        self::assertStringContainsString('name="_csrf" value="csrf-token"',$html);
        self::assertStringContainsString('name="action" value="reply"',$html);
        self::assertStringContainsString('/community/bugs',$html);
    }

    public function testMyReportsListShowsStatusDateCategoryAndEscapesTitle():void
    {
        $at=new DateTimeImmutable('2026-09-18T20:00:00+00:00');
        $report=new BugReport(
            EntityId::fromString(str_repeat('a',32)),
            'general',
            EntityId::fromString(str_repeat('1',32)),
            null,
            '<script>bad</script>',
            'summary',
            BugReportSeverity::High,
            BugReportStatus::InReview,
            null,
            $at,
            $at,
            1,
        );

        $html=MyBugReportsHtml::page([$report],['general'=>'Genel'],new BasePath('/community'));

        self::assertStringContainsString('Genel',$html);
        self::assertStringContainsString('İncelemede',$html);
        self::assertStringContainsString('2026-09-18 20:00',$html);
        self::assertStringContainsString('/community/bugs/'.str_repeat('a',32),$html);
        self::assertStringNotContainsString('<script>bad</script>',$html);
    }
}
