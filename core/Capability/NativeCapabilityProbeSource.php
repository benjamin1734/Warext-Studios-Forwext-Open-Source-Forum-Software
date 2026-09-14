<?php

declare(strict_types=1);

namespace Forwext\Core\Capability;

use PDO;

final readonly class NativeCapabilityProbeSource implements CapabilityProbeSource
{
    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function sapi(): string
    {
        return PHP_SAPI;
    }

    public function extensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    public function extensionVersion(string $extension): ?string
    {
        $version = phpversion($extension);
        return is_string($version) && $version !== '' ? $version : null;
    }

    public function functionAvailable(string $function): bool
    {
        return function_exists($function);
    }

    public function iniValue(string $name): ?string
    {
        $value = ini_get($name);
        return is_string($value) ? $value : null;
    }

    public function pdoDrivers(): array
    {
        return array_values(PDO::getAvailableDrivers());
    }
}
