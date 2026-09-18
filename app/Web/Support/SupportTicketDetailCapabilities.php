<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

final readonly class SupportTicketDetailCapabilities
{
    public function __construct(
        public bool $canReply,
        public bool $canInternalNote,
        public bool $canAssign,
        public bool $canManageStatus,
        public bool $canEscalate,
        public bool $canMerge,
        public bool $canSplit,
        public bool $canManageCanned,
    ) {
    }
}
