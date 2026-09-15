<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Appearance;

use InvalidArgumentException;

final readonly class RoleColor
{
    private function __construct(private string $value)
    {
    }

    public static function fromHex(string $value): self
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^#[0-9A-F]{6}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Role color must be a six-digit hexadecimal color such as #4F46E5.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
