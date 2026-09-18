<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Diagnostic;

use Forwext\Core\Bug\Report\BugReport;

final readonly class BugReportSubmissionReceipt
{
    public function __construct(
        public BugReport $report,
        public BugDiagnosticContext $diagnosticContext,
    ) {
    }
}
