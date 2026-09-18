<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use Forwext\Core\Bug\Diagnostic\BugReportSubmissionReceipt;

final readonly class BugReportFormReceipt
{
    /** @param list<BugAttachmentRecord> $attachments */
    public function __construct(
        public BugReportSubmissionReceipt $submission,
        public BugReportIntake $intake,
        public array $attachments,
    ) {
    }
}
