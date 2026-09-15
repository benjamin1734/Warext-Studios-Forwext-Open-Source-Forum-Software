<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

use InvalidArgumentException;

final readonly class PermissionNamespace
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Permission namespace must be 2-32 lowercase ASCII characters.');
        }

        return new self($value);
    }

    public static function fromKey(PermissionKey $key): self
    {
        $separator = strpos($key->value(), '.');
        $namespace = $separator === false ? $key->value() : substr($key->value(), 0, $separator);

        return self::fromString($namespace);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function contains(PermissionKey $key): bool
    {
        return self::fromKey($key)->value === $this->value;
    }
}
