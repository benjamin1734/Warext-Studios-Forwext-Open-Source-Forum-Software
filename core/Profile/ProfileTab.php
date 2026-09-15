<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

final readonly class ProfileTab
{
    public function __construct(
        public string $key,
        public bool $enabled,
        public ProfileVisibility $visibility,
        public int $sortOrder,
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $key) !== 1
            || $sortOrder < 0
            || $sortOrder > 1000
        ) {
            throw new ProfileException('Profile tab is invalid.');
        }
    }
}
