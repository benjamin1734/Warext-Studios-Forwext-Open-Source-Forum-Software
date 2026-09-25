<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use InvalidArgumentException;

final class AddonUiRegistry
{
    /** @var array<string,AddonUiRegistration> */
    private array $registrations = [];

    public function register(AddonUiRegistration $registration): void
    {
        $id = $registration->addonId->value();
        if (isset($this->registrations[$id])) {
            throw new InvalidArgumentException('Add-on UI registration is already loaded: ' . $id);
        }

        $this->registrations[$id] = $registration;
    }

    public function find(string $addonId): ?AddonUiRegistration
    {
        return $this->registrations[$addonId] ?? null;
    }

    /** @return list<AddonUiRegistration> */
    public function all(): array
    {
        $registrations = $this->registrations;
        ksort($registrations, SORT_STRING);

        return array_values($registrations);
    }
}
