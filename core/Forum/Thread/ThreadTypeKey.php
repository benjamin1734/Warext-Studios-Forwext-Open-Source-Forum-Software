<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use InvalidArgumentException;

final readonly class ThreadTypeKey
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (strlen($value) < 2 || strlen($value) > 64
            || preg_match('/^[a-z][a-z0-9_.-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('Thread type key must be 2-64 lowercase ASCII identifier characters.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
