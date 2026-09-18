<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Approval;

use InvalidArgumentException;

final readonly class ApprovalQueueSnapshot
{
    /** @param list<ApprovalQueueItem> $items */
    public function __construct(
        public int $total,
        public array $items,
    ) {
        if ($this->total < 0) {
            throw new InvalidArgumentException('Approval queue snapshot is invalid.');
        }
        foreach ($this->items as $item) {
            if (!$item instanceof ApprovalQueueItem) {
                throw new InvalidArgumentException('Approval queue snapshot contains an invalid item.');
            }
        }
    }
}
