<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Report;

use InvalidArgumentException;

final readonly class BugReportCategory
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public BugReportSeverity $defaultSeverity,
        public int $sortOrder,
        public bool $active,
    ) {
        self::assertKey($this->key);
        if (trim($this->label) === '' || strlen($this->label) > 100) {
            throw new InvalidArgumentException('Bug report category label must contain 1-100 UTF-8 bytes.');
        }
        if (strlen($this->description) > 255) {
            throw new InvalidArgumentException('Bug report category description cannot exceed 255 UTF-8 bytes.');
        }
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('Bug report category sort order is invalid.');
        }
    }

    public static function assertKey(string $key): void
    {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Bug report category key is invalid.');
        }
    }
}
