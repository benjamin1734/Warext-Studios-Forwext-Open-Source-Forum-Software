<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Conversation;

use Forwext\Core\Bug\Report\BugReport;

final readonly class BugReportConversationView
{
    /**
     * @param list<BugReportMessage> $messages
     * @param list<\Forwext\Core\Bug\Report\BugReportHistoryEntry> $history
     */
    public function __construct(
        public BugReport $report,
        public array $messages,
        public array $history,
        public bool $staffView,
    ) {
    }
}
