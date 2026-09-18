<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Conversation;

use Forwext\Core\Bug\Report\BugReport;

interface BugReportNotifier
{
    public function staffReply(BugReport $report,BugReportMessage $message): void;
    public function reporterReply(BugReport $report,BugReportMessage $message): void;
    public function statusChanged(BugReport $report): void;
}
