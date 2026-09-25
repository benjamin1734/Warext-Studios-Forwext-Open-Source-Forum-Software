<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use Forwext\Core\Addon\AddonId;
use InvalidArgumentException;
use Stringable;

final readonly class AddonBackendNamespace implements Stringable
{
    private function __construct(
        public AddonId $addonId,
        private string $prefix,
    ) {
    }

    public static function fromAddonId(AddonId $addonId): self
    {
        return new self(
            $addonId,
            'addon.' . strtolower($addonId->vendor()) . '.' . strtolower($addonId->name()),
        );
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function owns(string $key): bool
    {
        return str_starts_with(strtolower($key), $this->prefix . '.');
    }

    public function assertOwned(string $key, string $label): void
    {
        if (!$this->owns($key)) {
            throw new InvalidArgumentException(sprintf(
                '%s must use add-on namespace "%s.*".',
                $label,
                $this->prefix,
            ));
        }
    }

    public function __toString(): string
    {
        return $this->prefix;
    }
}
