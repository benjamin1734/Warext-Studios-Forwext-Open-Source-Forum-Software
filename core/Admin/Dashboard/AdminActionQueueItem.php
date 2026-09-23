<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Dashboard;

use InvalidArgumentException;

final readonly class AdminActionQueueItem
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $navigationKey,
        public int $count,
    ) {
        if (preg_match('/^[a-z][a-z0-9.-]{2,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Admin action queue key is invalid.');
        }
        if ($this->label === '' || $this->description === '') {
            throw new InvalidArgumentException('Admin action queue text is invalid.');
        }
        if (preg_match('/^admin\.[a-z][a-z0-9.-]{1,62}$/D', $this->navigationKey) !== 1) {
            throw new InvalidArgumentException('Admin action queue navigation key is invalid.');
        }
        if ($this->count < 0) {
            throw new InvalidArgumentException('Admin action queue count cannot be negative.');
        }
    }
}
