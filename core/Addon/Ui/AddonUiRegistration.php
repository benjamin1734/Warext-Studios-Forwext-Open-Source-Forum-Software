<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Backend\AddonBackendNamespace;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use Forwext\Core\Ui\DesignToken\DesignTokenDefinition;
use Forwext\Core\Ui\Layout\UiSlotContributor;
use Forwext\Core\Ui\Layout\UiSlotDefinition;
use Forwext\Core\Ui\Layout\UiSlotRegistry;
use Forwext\Core\Ui\Navigation\NavigationContributor;
use Forwext\Core\Ui\Navigation\NavigationItem;
use Forwext\Core\Ui\Navigation\NavigationRegistry;
use Forwext\Core\Ui\Widget\Widget;
use Forwext\Core\Ui\Widget\WidgetContributor;
use Forwext\Core\Ui\Widget\WidgetRegistry;
use InvalidArgumentException;

final class AddonUiRegistration implements UiSlotContributor, WidgetContributor, NavigationContributor
{
    private AddonBackendNamespace $namespace;

    /** @var array<string,UiSlotDefinition> */
    private array $slots = [];
    /** @var array<string,Widget> */
    private array $widgets = [];
    /** @var array<string,NavigationItem> */
    private array $navigation = [];
    /** @var array<string,DesignTokenDefinition> */
    private array $designTokens = [];

    public function __construct(public readonly AddonId $addonId)
    {
        $this->namespace = AddonBackendNamespace::fromAddonId($addonId);
    }

    public function ownerKey(): string
    {
        return $this->namespace->prefix();
    }

    public function slot(UiSlotDefinition $slot): self
    {
        $this->namespace->assertOwned($slot->key, 'Add-on UI slot');
        $this->put($this->slots, $slot->key, $slot, 'UI slot');

        return $this;
    }

    public function widget(Widget $widget): self
    {
        $this->namespace->assertOwned($widget->key(), 'Add-on widget');
        $this->put($this->widgets, $widget->key(), $widget, 'widget');

        return $this;
    }

    public function navigation(NavigationItem $item): self
    {
        $this->namespace->assertOwned($item->key, 'Add-on navigation item');
        if ($item->addonKey !== $this->ownerKey()) {
            throw new InvalidArgumentException('Add-on navigation item owner does not match the UI registration owner.');
        }
        $this->put($this->navigation, $item->key, $item, 'navigation item');

        return $this;
    }

    public function designToken(DesignTokenDefinition $definition): self
    {
        $this->namespace->assertOwned($definition->key, 'Add-on design token');
        $this->put($this->designTokens, $definition->key, $definition, 'design token');

        return $this;
    }

    public function registerSlots(UiSlotRegistry $registry): void
    {
        foreach ($this->ordered($this->slots) as $slot) {
            $registry->registerAddon($this->ownerKey(), $slot);
        }
    }

    public function registerWidgets(WidgetRegistry $registry): void
    {
        foreach ($this->ordered($this->widgets) as $widget) {
            $registry->registerAddon($this->ownerKey(), $widget);
        }
    }

    public function registerNavigation(NavigationRegistry $registry): void
    {
        foreach ($this->ordered($this->navigation) as $item) {
            $registry->registerAddon($this->ownerKey(), $item);
        }
    }

    public function extendDesignTokens(DesignTokenCatalog $base): DesignTokenCatalog
    {
        $definitions = $base->definitions();
        array_push($definitions, ...$this->ordered($this->designTokens));

        return new DesignTokenCatalog($base->manifestVersion, $definitions);
    }

    /** @return list<UiSlotDefinition> */
    public function slots(): array
    {
        return $this->ordered($this->slots);
    }

    /** @return list<Widget> */
    public function widgets(): array
    {
        return $this->ordered($this->widgets);
    }

    /** @return list<NavigationItem> */
    public function navigationItems(): array
    {
        return $this->ordered($this->navigation);
    }

    /** @return list<DesignTokenDefinition> */
    public function designTokens(): array
    {
        return $this->ordered($this->designTokens);
    }

    /** @template T @param array<string,T> $items @param T $value */
    private function put(array &$items, string $key, mixed $value, string $label): void
    {
        if (isset($items[$key])) {
            throw new InvalidArgumentException('Duplicate add-on ' . $label . ': ' . $key);
        }
        $items[$key] = $value;
    }

    /** @template T @param array<string,T> $items @return list<T> */
    private function ordered(array $items): array
    {
        ksort($items, SORT_STRING);

        return array_values($items);
    }
}
