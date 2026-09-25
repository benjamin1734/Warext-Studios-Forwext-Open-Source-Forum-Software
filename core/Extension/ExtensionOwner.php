<?php

declare(strict_types=1);

namespace Forwext\Core\Extension;

use InvalidArgumentException;
use Stringable;

final readonly class ExtensionOwner implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function core(): self
    {
        return new self('core');
    }

    public static function module(string $module): self
    {
        $module = trim($module);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/D', $module) !== 1) {
            throw new InvalidArgumentException('Extension module owner id is invalid.');
        }

        return new self('module:' . $module);
    }

    public static function addon(string $addonId): self
    {
        $addonId = trim($addonId);
        if (preg_match('/^[A-Za-z][A-Za-z0-9]{1,63}\/[A-Za-z][A-Za-z0-9]{1,63}$/D', $addonId) !== 1) {
            throw new InvalidArgumentException('Extension add-on owner id must use Vendor/AddOn form.');
        }

        return new self('addon:' . $addonId);
    }

    public function value(): string
    {
        return $this->value;
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
