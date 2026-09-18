<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

final readonly class DisciplineCapabilities
{
    public function __construct(
        public bool $canIssueWarning,
        public bool $canManageWarningDefinitions,
        public bool $canRestrict,
        public bool $canBan,
        public bool $canRevoke,
    ) {
    }
}
