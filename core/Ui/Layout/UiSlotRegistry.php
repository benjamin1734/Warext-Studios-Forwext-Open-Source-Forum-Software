<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout;

use InvalidArgumentException;

final class UiSlotRegistry
{
    /** @var array<string, UiSlotDefinition> */
    private array $slots = [];

    /** @param iterable<UiSlotContributor> $contributors */
    public static function withCoreDefaults(iterable $contributors = []): self
    {
        $registry = new self();
        foreach ([
            new UiSlotDefinition('page.before', LayoutRegion::Page, 100, 'Before the complete page shell.'),
            new UiSlotDefinition('header.before', LayoutRegion::Header, 200, 'Inside header before primary navigation shell.'),
            new UiSlotDefinition('header.after', LayoutRegion::Header, 300, 'Inside header after primary navigation shell.'),
            new UiSlotDefinition('main.before', LayoutRegion::Main, 400, 'Before page-specific main content.'),
            new UiSlotDefinition('main.after', LayoutRegion::Main, 500, 'After page-specific main content.'),
            new UiSlotDefinition('sidebar.primary', LayoutRegion::Sidebar, 600, 'Primary contextual sidebar.'),
            new UiSlotDefinition('footer.before', LayoutRegion::Footer, 700, 'Inside footer before core footer widgets.'),
            new UiSlotDefinition('footer.after', LayoutRegion::Footer, 800, 'Inside footer after core footer widgets.'),
            new UiSlotDefinition('page.after', LayoutRegion::Page, 900, 'After the complete page shell.'),
        ] as $slot) {
            $registry->register($slot);
        }

        foreach ($contributors as $contributor) {
            $contributor->registerSlots($registry);
        }

        return $registry;
    }

    public function register(UiSlotDefinition $slot): void
    {
        if (isset($this->slots[$slot->key])) {
            throw new InvalidArgumentException('UI slot key is already registered: ' . $slot->key);
        }

        $this->slots[$slot->key] = $slot;
    }

    public function registerModule(string $moduleKey, UiSlotDefinition $slot): void
    {
        self::assertExtensionNamespace($moduleKey, $slot->key, 64);
        $this->register($slot);
    }

    public function registerAddon(string $addonKey, UiSlotDefinition $slot): void
    {
        self::assertExtensionNamespace($addonKey, $slot->key, 135);
        $this->register($slot);
    }

    public function get(string $key): UiSlotDefinition
    {
        return $this->slots[$key]
            ?? throw new InvalidArgumentException('Unknown UI slot: ' . $key);
    }

    /** @return list<UiSlotDefinition> */
    public function all(): array
    {
        $slots = array_values($this->slots);
        usort(
            $slots,
            static fn (UiSlotDefinition $left, UiSlotDefinition $right): int =>
                [$left->order, $left->key] <=> [$right->order, $right->key],
        );

        return $slots;
    }

    private static function assertExtensionNamespace(string $ownerKey, string $slotKey, int $maximumOwnerLength): void
    {
        if (
            strlen($ownerKey) > $maximumOwnerLength
            || preg_match('/^[a-z][a-z0-9_.-]+$/D', $ownerKey) !== 1
            || !str_starts_with($slotKey, $ownerKey . '.')
        ) {
            throw new InvalidArgumentException('Extension UI slot must use its owner namespace.');
        }
    }
}
