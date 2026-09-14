<?php

declare(strict_types=1);

namespace Forwext\Core\Capability;

interface CapabilityProbeSource
{
    public function phpVersion(): string;

    public function sapi(): string;

    public function extensionLoaded(string $extension): bool;

    public function extensionVersion(string $extension): ?string;

    public function functionAvailable(string $function): bool;

    public function iniValue(string $name): ?string;

    /** @return list<string> */
    public function pdoDrivers(): array;
}
