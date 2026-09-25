<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use InvalidArgumentException;
use Stringable;

final readonly class AddonId implements Stringable
{
    private const PATTERN = '/^([A-Za-z][A-Za-z0-9]{1,63})\/([A-Za-z][A-Za-z0-9]{1,63})$/D';

    private function __construct(
        private string $value,
        private string $vendor,
        private string $name,
    ) {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        $matches = [];
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            throw new InvalidArgumentException('Add-on id must use canonical Vendor/AddOn form.');
        }

        return new self($value, $matches[1], $matches[2]);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function vendor(): string
    {
        return $this->vendor;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function auditKey(): string
    {
        return $this->vendor . ':' . $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
