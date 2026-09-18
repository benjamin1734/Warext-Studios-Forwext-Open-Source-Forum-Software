<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Report;

final readonly class NullBugReportNotifier implements BugReportNotifier
{
    public function staffResponse(BugReport $report, BugReportHistoryEntry $entry): void
    {
    }

    public function statusChanged(BugReport $report): void
    {
    }

    public function reporterInfoAdded(BugReport $report, BugReportHistoryEntry $entry): void
    {
    }
}
