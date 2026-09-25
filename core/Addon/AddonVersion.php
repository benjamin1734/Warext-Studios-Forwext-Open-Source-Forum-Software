<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use InvalidArgumentException;
use Stringable;

final readonly class AddonVersion implements Stringable
{
    private const PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D';

    /** @var list<string> */
    private array $preRelease;

    /**
     * @param list<string> $preRelease
     */
    private function __construct(
        private string $value,
        public int $major,
        public int $minor,
        public int $patch,
        array $preRelease,
    ) {
        $this->preRelease = $preRelease;
    }

    public static function parse(string $value): self
    {
        $value = trim($value);
        $matches = [];
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            throw new InvalidArgumentException('Add-on version must be valid SemVer.');
        }

        $major = self::component($matches[1]);
        $minor = self::component($matches[2]);
        $patch = self::component($matches[3]);

        $preRelease = ($matches[4] ?? '') === '' ? [] : explode('.', $matches[4]);
        foreach ($preRelease as $identifier) {
            if (ctype_digit($identifier) && strlen($identifier) > 1 && $identifier[0] === '0') {
                throw new InvalidArgumentException('Numeric SemVer prerelease identifiers may not contain leading zeroes.');
            }
        }

        return new self($value, $major, $minor, $patch, $preRelease);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function compare(self $other): int
    {
        $core = [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
        if ($core !== 0) {
            return $core;
        }

        if ($this->preRelease === [] && $other->preRelease === []) {
            return 0;
        }
        if ($this->preRelease === []) {
            return 1;
        }
        if ($other->preRelease === []) {
            return -1;
        }

        $count = max(count($this->preRelease), count($other->preRelease));
        for ($index = 0; $index < $count; ++$index) {
            $left = $this->preRelease[$index] ?? null;
            $right = $other->preRelease[$index] ?? null;
            if ($left === null) {
                return -1;
            }
            if ($right === null) {
                return 1;
            }
            if ($left === $right) {
                continue;
            }

            $leftNumeric = ctype_digit($left);
            $rightNumeric = ctype_digit($right);
            if ($leftNumeric && $rightNumeric) {
                $numeric = self::compareNumericIdentifier($left, $right);
                if ($numeric !== 0) {
                    return $numeric;
                }
                continue;
            }
            if ($leftNumeric !== $rightNumeric) {
                return $leftNumeric ? -1 : 1;
            }

            return strcmp($left, $right) <=> 0;
        }

        return 0;
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

    private static function component(string $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if (!is_int($parsed) || $parsed >= PHP_INT_MAX) {
            throw new InvalidArgumentException('SemVer numeric component exceeds the supported integer range.');
        }

        return $parsed;
    }

    private static function compareNumericIdentifier(string $left, string $right): int
    {
        $length = strlen($left) <=> strlen($right);
        if ($length !== 0) {
            return $length;
        }

        return strcmp($left, $right) <=> 0;
    }
}
