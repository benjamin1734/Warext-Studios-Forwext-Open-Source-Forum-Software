<?php

declare(strict_types=1);

namespace Forwext\Core\Queue;

use InvalidArgumentException;
use Stringable;

final readonly class JobId implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Queue job id must be 128-bit lowercase hexadecimal.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
