<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Approval;

use DateTimeImmutable;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;

interface ApprovalQueueProvider
{
    /** @return list<string> */
    public function sourceTypes(): array;

    public function count(): int;

    /** @return list<ApprovalQueueItem> */
    public function latest(int $limit): array;

    /** @param list<ApprovalQueueSelection> $selections */
    public function moderate(
        ApprovalQueueAction $action,
        array $selections,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void;
}
