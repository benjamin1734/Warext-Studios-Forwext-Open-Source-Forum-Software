<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use InvalidArgumentException;
use Stringable;

final readonly class MigrationId implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match('/^[0-9]{14}_[a-z0-9][a-z0-9_]{0,79}$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'Migration id must use YYYYMMDDHHMMSS_slug with lowercase alphanumeric/underscore slug.',
            );
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
