<?php

declare(strict_types=1);

namespace Forwext\Core\Queue;

use InvalidArgumentException;
use Stringable;

final readonly class QueueName implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Queue name is invalid.');
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
