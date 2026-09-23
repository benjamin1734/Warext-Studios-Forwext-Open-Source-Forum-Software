<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Layout\LayoutRegion;
use Forwext\Core\Ui\Layout\UiSlotDefinition;
use Forwext\Core\Ui\Layout\UiSlotRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UiSlotRegistryTest extends TestCase
{
    public function testCoreRegionsAndNamedSlotsAreRegistered(): void
    {
        $registry = UiSlotRegistry::withCoreDefaults();
        $slots = array_map(static fn ($slot): string => $slot->key, $registry->all());

        foreach ([
            'page.before',
            'header.before',
            'header.after',
            'main.before',
            'main.after',
            'sidebar.primary',
            'footer.before',
            'footer.after',
            'page.after',
        ] as $slot) {
            self::assertContains($slot, $slots);
        }

        self::assertSame(LayoutRegion::Sidebar, $registry->get('sidebar.primary')->region);
    }

    public function testModuleAndAddonSlotsMustUseOwnerNamespace(): void
    {
        $registry = UiSlotRegistry::withCoreDefaults();
        $registry->registerModule(
            'marketplace',
            new UiSlotDefinition(
                'marketplace.sidebar-featured',
                LayoutRegion::Sidebar,
                100,
                'Marketplace featured sidebar slot.',
            ),
        );

        $this->expectException(InvalidArgumentException::class);
        $registry->registerAddon(
            'vendor.addon',
            new UiSlotDefinition(
                'other.slot',
                LayoutRegion::Page,
                100,
                'Invalid namespace slot.',
            ),
        );
    }
}
