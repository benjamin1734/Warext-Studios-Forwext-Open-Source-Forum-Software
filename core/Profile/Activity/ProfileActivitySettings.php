<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

final readonly class ProfileActivitySettings
{
    public function __construct(
        public ProfileActivityScope $viewScope = ProfileActivityScope::Everyone,
        public ProfileActivityScope $postScope = ProfileActivityScope::Everyone,
    ) {
    }
}
