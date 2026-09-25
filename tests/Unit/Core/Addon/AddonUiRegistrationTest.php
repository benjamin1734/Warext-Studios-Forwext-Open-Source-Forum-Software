<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Ui\AddonUiRegistration;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use Forwext\Core\Ui\DesignToken\DesignTokenCategory;
use Forwext\Core\Ui\DesignToken\DesignTokenDefinition;
use Forwext\Core\Ui\Layout\LayoutRegion;
use Forwext\Core\Ui\Layout\UiSlotDefinition;
use Forwext\Core\Ui\Layout\UiSlotRegistry;
use Forwext\Core\Ui\Navigation\NavigationItem;
use Forwext\Core\Ui\Navigation\NavigationRegistry;
use Forwext\Core\Ui\Widget\Widget;
use Forwext\Core\Ui\Widget\WidgetContext;
use Forwext\Core\Ui\Widget\WidgetOwnerType;
use Forwext\Core\Ui\Widget\WidgetRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonUiRegistrationTest extends TestCase
{
    public function testAddonUiContributesSlotsWidgetsNavigationAndDesignTokensThroughCoreRegistries(): void
    {
        $registration = new AddonUiRegistration(AddonId::fromString('Acme/Demo'));
        $registration
            ->slot(new UiSlotDefinition(
                'addon.acme.demo.panel',
                LayoutRegion::Sidebar,
                650,
                'Demo add-on sidebar panel.',
            ))
            ->widget(new AddonUiWidgetFixture())
            ->navigation(new NavigationItem(
                'addon.acme.demo.navigation',
                'Demo',
                '/demo',
                280,
                addonKey: 'addon.acme.demo',
            ))
            ->designToken(new DesignTokenDefinition(
                'addon.acme.demo.color.brand',
                DesignTokenCategory::Color,
                '#112233',
            ));

        $slots = UiSlotRegistry::withCoreDefaults([$registration]);
        $widgets = WidgetRegistry::withCoreDefaults($slots, [$registration]);
        $navigation = NavigationRegistry::withCoreDefaults([$registration]);
        $tokens = $registration->extendDesignTokens(DesignTokenCatalog::coreDefaults());

        self::assertSame('addon.acme.demo.panel', $slots->get('addon.acme.demo.panel')->key);
        self::assertSame(WidgetOwnerType::Addon, $widgets->get('addon.acme.demo.widget')->ownerType);
        self::assertSame(
            'addon.acme.demo.navigation',
            array_values(array_filter(
                $navigation->visible(true),
                static fn (NavigationItem $item): bool => $item->addonKey !== null,
            ))[0]->key,
        );
        self::assertSame('#112233', $tokens->resolveValue('addon.acme.demo.color.brand'));
    }

    public function testAddonUiRejectsCapabilitiesOutsideItsNamespace(): void
    {
        $registration = new AddonUiRegistration(AddonId::fromString('Acme/Demo'));

        $this->expectException(InvalidArgumentException::class);
        $registration->slot(new UiSlotDefinition(
            'other.panel',
            LayoutRegion::Sidebar,
            650,
            'Wrong namespace.',
        ));
    }
}

final readonly class AddonUiWidgetFixture implements Widget
{
    public function key(): string
    {
        return 'addon.acme.demo.widget';
    }

    public function slot(): string
    {
        return 'addon.acme.demo.panel';
    }

    public function order(): int
    {
        return 10;
    }

    public function cacheTtlSeconds(): int
    {
        return 0;
    }

    public function render(WidgetContext $context): string
    {
        return '<p>demo</p>';
    }
}
