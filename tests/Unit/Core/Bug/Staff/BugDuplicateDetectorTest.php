<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Bug\Staff;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Bug\Staff\BugDuplicateDetector;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class BugDuplicateDetectorTest extends TestCase
{
    public function testDetectorRanksCloseCandidateButDoesNotMutateEitherReport(): void
    {
        $source=$this->report('a','Editor save button fails','Editor save button returns error on submit.');
        $close=$this->report('b','Editor save button fails on submit','Editor save button returns an error when submitting.');
        $far=$this->report('c','Avatar upload is slow','Large avatars take several seconds to process.');

        $suggestions=(new BugDuplicateDetector(0.30,5))->suggest($source,[$far,$close]);

        self::assertNotEmpty($suggestions);
        self::assertSame($close->reportId->value(),$suggestions[0]->report->reportId->value());
        self::assertSame(BugReportStatus::New,$source->status);
        self::assertSame(BugReportStatus::New,$close->status);
    }

    private function report(string $seed,string $title,string $summary):BugReport
    {
        $at=new DateTimeImmutable('2026-09-18 20:00:00',new DateTimeZone('UTC'));
        return new BugReport(
            EntityId::fromString(str_repeat($seed,32)),
            'frontend',
            EntityId::fromString(str_repeat('1',32)),
            null,
            $title,
            $summary,
            BugReportSeverity::Medium,
            BugReportStatus::New,
            null,
            $at,
            $at,
            1,
        );
    }
}
