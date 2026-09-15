<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use InvalidArgumentException;

final readonly class ThreadTypeDefinition
{
    public function __construct(
        private ThreadTypeKey $key,
        private string $label,
        private bool $allowsReplies = true,
        private bool $system = false,
    ) {
        $label = trim($this->label);
        if ($label === '' || strlen($label) > 100) {
            throw new InvalidArgumentException('Thread type label must contain 1-100 UTF-8 bytes.');
        }
    }

    public function key(): ThreadTypeKey
    {
        return $this->key;
    }

    public function label(): string
    {
        return trim($this->label);
    }

    public function allowsReplies(): bool
    {
        return $this->allowsReplies;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }
}
