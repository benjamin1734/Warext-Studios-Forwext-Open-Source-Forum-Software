<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

final readonly class BugReportDetailCapabilities
{
    public function __construct(
        public bool $canReply,
        public bool $canManageStatus,
    ) {
    }
}
