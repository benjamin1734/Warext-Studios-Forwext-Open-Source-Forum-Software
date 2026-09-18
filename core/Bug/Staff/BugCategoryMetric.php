<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Staff;

use InvalidArgumentException;

final readonly class BugCategoryMetric
{
    public function __construct(
        public string $categoryKey,
        public string $categoryLabel,
        public int $total,
        public int $active,
        public int $terminal,
        public int $duplicates,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->categoryKey) !== 1
            || trim($this->categoryLabel) === ''
            || strlen($this->categoryLabel) > 100
        ) {
            throw new InvalidArgumentException('Bug category metric identity is invalid.');
        }
        foreach ([$this->total,$this->active,$this->terminal,$this->duplicates] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Bug category metric values cannot be negative.');
            }
        }
    }
}
