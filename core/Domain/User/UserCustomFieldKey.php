<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use InvalidArgumentException;
use Stringable;

final readonly class UserCustomFieldKey implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $value) !== 1) {
            throw new InvalidArgumentException('User custom field key is invalid.');
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
