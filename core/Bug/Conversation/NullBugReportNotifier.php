<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Conversation;

use Forwext\Core\Bug\Report\BugReport;

final readonly class NullBugReportNotifier implements BugReportNotifier
{
    public function staffReply(BugReport $report,BugReportMessage $message): void {}
    public function reporterReply(BugReport $report,BugReportMessage $message): void {}
    public function statusChanged(BugReport $report): void {}
}
