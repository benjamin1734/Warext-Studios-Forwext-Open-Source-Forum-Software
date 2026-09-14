<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Entity;

use InvalidArgumentException;
use Stringable;

final readonly class EntityId implements Stringable
{
    private const MAX_LENGTH = 191;

    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (
            $value === ''
            || strlen($value) > self::MAX_LENGTH
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('Entity id contains unsupported characters or length.');
        }

        return new self($value);
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw new InvalidArgumentException('Numeric entity id must be positive.');
        }

        return new self((string) $value);
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
