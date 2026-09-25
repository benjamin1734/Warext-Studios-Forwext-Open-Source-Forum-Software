<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Operations;

use InvalidArgumentException;

final readonly class SystemLogEntry
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public string $timestamp,
        public string $level,
        public string $message,
        public array $context,
    ) {
        if ($this->timestamp === '' || strlen($this->timestamp) > 64) {
            throw new InvalidArgumentException('System log timestamp is invalid.');
        }
        if (preg_match('/^[a-z]{3,16}$/D', $this->level) !== 1) {
            throw new InvalidArgumentException('System log level is invalid.');
        }
        if (strlen($this->message) > 8192) {
            throw new InvalidArgumentException('System log message is too long.');
        }
    }
}
