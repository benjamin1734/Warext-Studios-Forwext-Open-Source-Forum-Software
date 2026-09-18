<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use Forwext\Core\Domain\Entity\EntityId;

interface BugReportIntakeRepository
{
    public function saveDetails(BugReportDetails $details): void;

    public function details(EntityId $reportId): ?BugReportDetails;

    public function saveAttachment(BugAttachmentRecord $attachment): void;

    /** @return list<BugAttachmentRecord> */
    public function attachments(EntityId $reportId): array;
}
