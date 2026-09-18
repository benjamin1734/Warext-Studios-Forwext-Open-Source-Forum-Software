<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use Forwext\Core\Domain\Entity\EntityId;

interface BugReportIntakeRepository
{
    public function saveIntake(BugReportIntake $intake): void;
    public function intake(EntityId $reportId): ?BugReportIntake;
    public function saveAttachment(BugAttachmentRecord $attachment): void;

    /** @return list<BugAttachmentRecord> */
    public function attachments(EntityId $reportId): array;
}
