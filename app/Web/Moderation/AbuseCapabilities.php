<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

final readonly class AbuseCapabilities
{
    public function __construct(
        public bool $canManageRules,
        public bool $canCleanup,
    ) {
    }
}
