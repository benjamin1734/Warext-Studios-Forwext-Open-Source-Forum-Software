<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use InvalidArgumentException;

final readonly class ReportReason
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public int $sortOrder,
        public bool $active,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Report reason key is invalid.');
        }
        if (trim($this->label) === '' || strlen($this->label) > 100) {
            throw new InvalidArgumentException('Report reason label must contain 1-100 UTF-8 bytes.');
        }
        if (strlen($this->description) > 255) {
            throw new InvalidArgumentException('Report reason description cannot exceed 255 UTF-8 bytes.');
        }
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('Report reason sort order is invalid.');
        }
    }
}
