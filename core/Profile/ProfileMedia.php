<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

final readonly class ProfileMedia
{
    public function __construct(
        public string $contents,
        public string $contentType,
    ) {
        if ($contents === '' || !in_array($contentType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ProfileException('Profile media payload is invalid.');
        }
    }
}
