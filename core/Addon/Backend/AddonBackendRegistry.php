<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use Forwext\Core\Admin\Navigation\AdminNavigationItem;
use Forwext\Core\Domain\Access\Permission\PermissionCatalogEntry;
use Forwext\Core\Migration\Migration;
use InvalidArgumentException;

final class AddonBackendRegistry
{
    /** @var array<string,AddonBackendRegistration> */
    private array $registrations = [];

    public function register(AddonBackendRegistration $registration): void
    {
        $id = $registration->addonId->value();
        if (isset($this->registrations[$id])) {
            throw new InvalidArgumentException('Add-on backend registration is already loaded: ' . $id);
        }
        $this->registrations[$id] = $registration;
    }

    public function find(string $addonId): ?AddonBackendRegistration
    {
        return $this->registrations[$addonId] ?? null;
    }

    /** @return list<AddonBackendRegistration> */
    public function all(): array
    {
        $registrations = $this->registrations;
        ksort($registrations, SORT_STRING);
        return array_values($registrations);
    }

    /** @return list<Migration> */
    public function migrations(): array
    {
        return $this->merge(static fn (AddonBackendRegistration $r): array => $r->migrations());
    }

    /** @return list<PermissionCatalogEntry> */
    public function permissions(): array
    {
        return $this->merge(static fn (AddonBackendRegistration $r): array => $r->permissions());
    }

    /** @return list<AddonSettingDefinition> */
    public function settings(): array
    {
        return $this->merge(static fn (AddonBackendRegistration $r): array => $r->settings());
    }

    /** @return list<AdminNavigationItem> */
    public function adminItems(): array
    {
        return $this->merge(static fn (AddonBackendRegistration $r): array => $r->adminItems());
    }

    /** @return list<AddonEntityDefinition> */
    public function entities(): array
    {
        return $this->merge(static fn (AddonBackendRegistration $r): array => $r->entities());
    }

    /** @return list<AddonWebhookDefinition> */
    public function webhooks(): array
    {
        return $this->merge(static fn (AddonBackendRegistration $r): array => $r->webhooks());
    }

    /** @return list<AddonContentTypeDefinition> */
    public function contentTypes(): array
    {
        return $this->merge(static fn (AddonBackendRegistration $r): array => $r->contentTypes());
    }

    /**
     * @template T
     * @param callable(AddonBackendRegistration):list<T> $reader
     * @return list<T>
     */
    private function merge(callable $reader): array
    {
        $result = [];
        foreach ($this->all() as $registration) {
            array_push($result, ...$reader($registration));
        }
        return $result;
    }
}
