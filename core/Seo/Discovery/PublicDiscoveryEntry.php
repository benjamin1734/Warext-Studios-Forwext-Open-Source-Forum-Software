<?php

declare(strict_types=1);

namespace Forwext\Core\Seo\Discovery;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PublicDiscoveryEntry
{
    public function __construct(
        public string $path,
        public string $title,
        public string $summary,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        if (
            $this->path === ''
            || $this->path[0] !== '/'
            || str_starts_with($this->path, '//')
            || str_contains($this->path, '?')
            || str_contains($this->path, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $this->path) === 1
        ) {
            throw new InvalidArgumentException('Public discovery path must be a safe absolute URL path.');
        }
        foreach (explode('/', trim($this->path, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Public discovery path cannot contain traversal segments.');
            }
        }
        if ($this->title === '' || strlen($this->title) > 200) {
            throw new InvalidArgumentException('Public discovery title must contain 1..200 bytes.');
        }
        if (strlen($this->summary) > 500) {
            throw new InvalidArgumentException('Public discovery summary cannot exceed 500 bytes.');
        }
    }
}
