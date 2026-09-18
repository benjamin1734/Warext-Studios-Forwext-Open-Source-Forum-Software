<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Conversation;

use Forwext\Core\Domain\Entity\EntityId;

interface BugReportConversationRepository
{
    public function append(BugReportMessage $message): void;

    /** @return list<BugReportMessage> */
    public function messages(EntityId $reportId, int $limit = 200): array;
}
