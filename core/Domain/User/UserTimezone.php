<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use DateTimeZone;
use InvalidArgumentException;
use Stringable;

final readonly class UserTimezone implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 64) {
            throw new InvalidArgumentException('Timezone is empty or too long.');
        }

        $allowed = timezone_identifiers_list(DateTimeZone::ALL_WITH_BC);
        if ($value !== 'UTC' && !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Timezone must be a named IANA timezone identifier.');
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
