<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use InvalidArgumentException;
use Stringable;

final readonly class SemanticVersion implements Stringable
{
    private const SEMVER_PATTERN = '/^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*)?(?:\\+[0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*)?$/D';
    private const FORWEXT_SIDE_UPDATE_PATTERN = '/^(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.(0|[1-9][0-9]*)\\.([0-9]{2})-dev$/D';

    private function __construct(private string $value)
    {
    }

    public static function parse(string $value): self
    {
        if (preg_match(self::SEMVER_PATTERN, $value) === 1) {
            return new self($value);
        }

        $matches = [];
        if (preg_match(self::FORWEXT_SIDE_UPDATE_PATTERN, $value, $matches) === 1
            && isset($matches[4])
            && (int) $matches[4] >= 1
        ) {
            return new self($value);
        }

        throw new InvalidArgumentException(
            'Installed version must be a valid semantic version or Forwext development side-update version.',
        );
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
        $matches = [];
        if (preg_match(self::FORWEXT_SIDE_UPDATE_PATTERN, $value, $matches) === 1) {
            return sprintf(
                '%s.%s.%s-dev.%d',
                $matches[1],
                $matches[2],
                $matches[3],
                (int) $matches[4],
            );
        }

        return explode('+', $value, 2)[0];
    }
}
