<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

use InvalidArgumentException;

final readonly class WarningDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public int $points,
        public ?int $expiryDays,
        public bool $active,
        public int $sortOrder = 0,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Warning definition key is invalid.');
        }
        if (trim($this->label) === '' || strlen($this->label) > 100) {
            throw new InvalidArgumentException('Warning definition label must contain 1-100 bytes.');
        }
        if (strlen($this->description) > 500) {
            throw new InvalidArgumentException('Warning definition description cannot exceed 500 bytes.');
        }
        if ($this->points < 0 || $this->points > 1000) {
            throw new InvalidArgumentException('Warning points must be between 0 and 1000.');
        }
        if ($this->expiryDays !== null && ($this->expiryDays < 1 || $this->expiryDays > 3650)) {
            throw new InvalidArgumentException('Warning expiry days must be between 1 and 3650.');
        }
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('Warning sort order is outside the supported range.');
        }
    }
}
