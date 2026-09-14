<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use InvalidArgumentException;
use Stringable;

final readonly class SemanticVersion implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function parse(string $value): self
    {
        if (preg_match(
            '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',
            $value,
        ) !== 1) {
            throw new InvalidArgumentException('Installed version must be a valid semantic version.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isGreaterThan(self $other): bool
    {
        return version_compare(self::precedenceValue($this->value), self::precedenceValue($other->value), '>');
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function precedenceValue(string $value): string
    {
        return explode('+', $value, 2)[0];
    }
}
