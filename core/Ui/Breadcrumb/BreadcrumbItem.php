<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Breadcrumb;

use InvalidArgumentException;

final readonly class BreadcrumbItem
{
    public function __construct(
        public string $label,
        public ?string $path = null,
    ) {
        if ($this->label === '' || strlen($this->label) > 160) {
            throw new InvalidArgumentException('Breadcrumb label must contain 1..160 bytes.');
        }
        if (
            $this->path !== null
            && (
                $this->path === ''
                || $this->path[0] !== '/'
                || str_starts_with($this->path, '//')
                || str_contains($this->path, '?')
                || str_contains($this->path, '#')
            )
        ) {
            throw new InvalidArgumentException('Breadcrumb path must be a safe same-origin absolute path.');
        }
    }
}
