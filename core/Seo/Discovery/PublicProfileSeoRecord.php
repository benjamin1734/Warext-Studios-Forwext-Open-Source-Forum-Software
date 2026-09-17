<?php

declare(strict_types=1);

namespace Forwext\Core\Seo\Discovery;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PublicProfileSeoRecord
{
    public function __construct(
        public string $username,
        public ?string $customSlug,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($this->username === '') {
            throw new InvalidArgumentException('Public profile username cannot be empty.');
        }
    }

    public function canonicalPath(): string
    {
        return $this->customSlug !== null
            ? '/u/' . rawurlencode($this->customSlug)
            : '/members/' . rawurlencode($this->username);
    }
}
