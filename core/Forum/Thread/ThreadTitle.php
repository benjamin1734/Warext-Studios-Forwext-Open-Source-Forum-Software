<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use InvalidArgumentException;

final readonly class ThreadTitle
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 200 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Thread title must contain 1-200 UTF-8 bytes and no control characters.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
