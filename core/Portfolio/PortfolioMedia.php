<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

use InvalidArgumentException;

final readonly class PortfolioMedia
{
    public function __construct(
        public string $path,
        public string $alt,
        public int $sortOrder,
    ) {
        if (
            strlen($this->path) > 1024
            || preg_match('#^/[A-Za-z0-9][A-Za-z0-9/_%.:-]*\.(?:avif|gif|jpe?g|png|webp)$#Di', $this->path) !== 1
            || str_contains($this->path, '..')
            || str_starts_with($this->path, '//')
        ) {
            throw new InvalidArgumentException('Portfolio media must use a safe same-origin image path.');
        }
        if (strlen($this->alt) > 200 || $this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('Portfolio media metadata is invalid.');
        }
    }
}
