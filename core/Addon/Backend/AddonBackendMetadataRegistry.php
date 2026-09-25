<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use Forwext\Core\Addon\AddonId;
use InvalidArgumentException;

final class AddonBackendMetadataRegistry
{
    /** @var array<string,AddonEntityDefinition> */
    private array $entities = [];
    /** @var array<string,string> */
    private array $entityOwners = [];
    /** @var array<string,AddonWebhookDefinition> */
    private array $webhooks = [];
    /** @var array<string,string> */
    private array $webhookOwners = [];
    /** @var array<string,AddonContentTypeDefinition> */
    private array $contentTypes = [];
    /** @var array<string,string> */
    private array $contentTypeOwners = [];

    public function registerEntity(AddonId $owner, AddonEntityDefinition $definition): void
    {
        $this->register(
            $owner,
            $definition->key,
            $definition,
            $this->entities,
            $this->entityOwners,
            'entity',
        );
    }

    public function registerWebhook(AddonId $owner, AddonWebhookDefinition $definition): void
    {
        $this->register(
            $owner,
            $definition->key,
            $definition,
            $this->webhooks,
            $this->webhookOwners,
            'webhook',
        );
    }

    public function registerContentType(AddonId $owner, AddonContentTypeDefinition $definition): void
    {
        $this->register(
            $owner,
            $definition->key,
            $definition,
            $this->contentTypes,
            $this->contentTypeOwners,
            'content type',
        );
    }

    public function entity(string $key): ?AddonEntityDefinition
    {
        return $this->entities[$key] ?? null;
    }

    public function webhook(string $key): ?AddonWebhookDefinition
    {
        return $this->webhooks[$key] ?? null;
    }

    public function contentType(string $key): ?AddonContentTypeDefinition
    {
        return $this->contentTypes[$key] ?? null;
    }

    public function entityOwner(string $key): ?string
    {
        return $this->entityOwners[$key] ?? null;
    }

    public function webhookOwner(string $key): ?string
    {
        return $this->webhookOwners[$key] ?? null;
    }

    public function contentTypeOwner(string $key): ?string
    {
        return $this->contentTypeOwners[$key] ?? null;
    }

    /** @return list<AddonEntityDefinition> */
    public function entities(): array
    {
        return $this->ordered($this->entities);
    }

    /** @return list<AddonWebhookDefinition> */
    public function webhooks(): array
    {
        return $this->ordered($this->webhooks);
    }

    /** @return list<AddonContentTypeDefinition> */
    public function contentTypes(): array
    {
        return $this->ordered($this->contentTypes);
    }

    /**
     * @template T of object
     * @param array<string,T> $items
     * @param array<string,string> $owners
     */
    private function register(
        AddonId $owner,
        string $key,
        object $definition,
        array &$items,
        array &$owners,
        string $label,
    ): void {
        AddonBackendNamespace::fromAddonId($owner)->assertOwned($key, 'Add-on ' . $label . ' key');
        if (isset($items[$key])) {
            throw new InvalidArgumentException(sprintf(
                'Add-on backend %s "%s" is already registered.',
                $label,
                $key,
            ));
        }

        $items[$key] = $definition;
        $owners[$key] = $owner->value();
    }

    /**
     * @template T
     * @param array<string,T> $items
     * @return list<T>
     */
    private function ordered(array $items): array
    {
        ksort($items, SORT_STRING);

        return array_values($items);
    }
}
