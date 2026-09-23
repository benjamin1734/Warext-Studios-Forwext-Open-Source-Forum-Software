<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Layout\UiSlotRegistry;
use Forwext\Core\Ui\Widget\Widget;
use Forwext\Core\Ui\Widget\WidgetContext;
use Forwext\Core\Ui\Widget\WidgetOwnerType;
use Forwext\Core\Ui\Widget\WidgetRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WidgetRegistryTest extends TestCase
{
    public function testCoreAndModuleWidgetsAreOrderedAndOwnerTyped(): void
    {
        $registry = WidgetRegistry::withCoreDefaults(UiSlotRegistry::withCoreDefaults());
        $registry->registerModule('marketplace', new TestWidget(
            'marketplace.footer-links',
            'footer.after',
            100,
            0,
            '<span>Marketplace</span>',
        ));

        $widgets = $registry->forSlot('footer.after');

        self::assertCount(2, $widgets);
        self::assertSame('marketplace.footer-links', $widgets[0]->widget->key());
        self::assertSame(WidgetOwnerType::Module, $widgets[0]->ownerType);
        self::assertSame('core.brand-footer', $widgets[1]->widget->key());
        self::assertSame(WidgetOwnerType::Core, $widgets[1]->ownerType);
    }

    public function testAddonWidgetCannotEscapeOwnerNamespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $registry = WidgetRegistry::withCoreDefaults();
        $registry->registerAddon('vendor.addon', new TestWidget(
            'other.widget',
            'main.after',
            100,
            0,
            'x',
        ));
    }
}

final class TestWidget implements Widget
{
    public int $renders = 0;

    public function __construct(
        private readonly string $key,
        private readonly string $slot,
        private readonly int $order,
        private readonly int $ttl,
        private readonly string $html,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function slot(): string
    {
        return $this->slot;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function cacheTtlSeconds(): int
    {
        return $this->ttl;
    }

    public function render(WidgetContext $context): string
    {
        ++$this->renders;
        return $this->html;
    }
}
