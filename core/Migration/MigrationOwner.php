<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use InvalidArgumentException;

final readonly class MigrationOwner
{
    private function __construct(
        public MigrationScope $scope,
        public string $name,
    ) {
        $validLegacy = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/D', $name) === 1;
        $validAddonId = $scope === MigrationScope::Addon
            && preg_match('/^[A-Za-z][A-Za-z0-9]{1,63}\/[A-Za-z][A-Za-z0-9]{1,63}$/D', $name) === 1;
        if (!$validLegacy && !$validAddonId) {
            throw new InvalidArgumentException('Migration owner contains unsupported characters or length.');
        }
    }

    public static function core(): self
    {
        return new self(MigrationScope::Core, 'core');
    }

    public static function module(string $name): self
    {
        return new self(MigrationScope::Module, $name);
    }

    public static function addon(string $name): self
    {
        return new self(MigrationScope::Addon, $name);
    }

    public function key(): string
    {
        return $this->scope->value . ':' . $this->name;
    }
}
