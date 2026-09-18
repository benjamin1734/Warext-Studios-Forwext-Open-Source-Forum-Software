<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use Forwext\Core\Bug\Diagnostic\BugDiagnosticContext;
use Forwext\Core\Bug\Report\BugReport;

final readonly class BugReportFormSubmissionReceipt
{
    /** @param list<BugAttachmentRecord> $attachments */
    public function __construct(
        public BugReport $report,
        public BugDiagnosticContext $diagnosticContext,
        public BugReportDetails $details,
        public array $attachments,
    ) {
    }
}
