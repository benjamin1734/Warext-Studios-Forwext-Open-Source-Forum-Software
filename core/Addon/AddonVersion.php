<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use InvalidArgumentException;
use Stringable;

final readonly class AddonVersion implements Stringable
{
    private const PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

    private function __construct(
        private string $value,
        public int $major,
        public int $minor,
        public int $patch,
    ) {
    }

    public static function parse(string $value): self
    {
        $value = trim($value);
        $matches = [];
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            throw new InvalidArgumentException('Add-on version must be valid SemVer.');
        }

        return new self($value, (int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function compare(self $other): int
    {
        return version_compare(self::precedence($this->value), self::precedence($other->value));
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function precedence(string $value): string
    {
        return explode('+', $value, 2)[0];
    }
}
