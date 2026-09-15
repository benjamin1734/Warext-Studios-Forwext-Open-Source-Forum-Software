<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access;

use InvalidArgumentException;

final readonly class AccessIdentifier
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Access identifier must be 2-64 lowercase ASCII characters and start with a letter.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
