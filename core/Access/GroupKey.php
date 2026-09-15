<?php

declare(strict_types=1);

namespace Forwext\Core\Access;

use Stringable;

final readonly class GroupKey implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (
            strlen($value) < 2
            || strlen($value) > 64
            || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $value) !== 1
        ) {
            throw new AccessException('Group key is invalid.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
