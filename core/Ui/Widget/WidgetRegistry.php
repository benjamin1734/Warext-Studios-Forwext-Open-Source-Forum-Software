<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Widget;

use Forwext\Core\Ui\Layout\UiSlotRegistry;
use InvalidArgumentException;

final class WidgetRegistry
{
    /** @var array<string, RegisteredWidget> */
    private array $widgets = [];

    public function __construct(private readonly UiSlotRegistry $slots)
    {
    }

    /** @param iterable<WidgetContributor> $contributors */
    public static function withCoreDefaults(
        ?UiSlotRegistry $slots = null,
        iterable $contributors = [],
    ): self {
        $registry = new self($slots ?? UiSlotRegistry::withCoreDefaults());
        $registry->registerCore(new CoreBrandFooterWidget());

        foreach ($contributors as $contributor) {
            $contributor->registerWidgets($registry);
        }

        return $registry;
    }

    public function registerCore(Widget $widget): void
    {
        $this->register(WidgetOwnerType::Core, 'core', $widget);
    }

    public function registerModule(string $moduleKey, Widget $widget): void
    {
        $this->register(WidgetOwnerType::Module, $moduleKey, $widget);
    }

    public function registerAddon(string $addonKey, Widget $widget): void
    {
        $this->register(WidgetOwnerType::Addon, $addonKey, $widget);
    }

    /** @return list<RegisteredWidget> */
    public function forSlot(string $slot): array
    {
        $this->slots->get($slot);
        $widgets = array_values(array_filter(
            $this->widgets,
            static fn (RegisteredWidget $registration): bool =>
                $registration->widget->slot() === $slot,
        ));
        usort(
            $widgets,
            static fn (RegisteredWidget $left, RegisteredWidget $right): int =>
                [$left->widget->order(), $left->widget->key()]
                <=> [$right->widget->order(), $right->widget->key()],
        );

        return $widgets;
    }

    public function get(string $key): RegisteredWidget
    {
        return $this->widgets[$key]
            ?? throw new InvalidArgumentException('Unknown widget: ' . $key);
    }

    /** @return list<RegisteredWidget> */
    public function all(): array
    {
        $widgets = array_values($this->widgets);
        usort(
            $widgets,
            static fn (RegisteredWidget $left, RegisteredWidget $right): int =>
                $left->widget->key() <=> $right->widget->key(),
        );

        return $widgets;
    }

    private function register(
        WidgetOwnerType $ownerType,
        string $ownerKey,
        Widget $widget,
    ): void {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $ownerKey) !== 1) {
            throw new InvalidArgumentException('Widget owner key is invalid.');
        }

        $key = $widget->key();
        if (
            preg_match('/^[a-z][a-z0-9]*(?:\.[a-z0-9][a-z0-9-]*)+$/D', $key) !== 1
            || !str_starts_with($key, $ownerKey . '.')
        ) {
            throw new InvalidArgumentException('Widget key must use its owner namespace.');
        }

        if ($widget->order() < -10000 || $widget->order() > 10000) {
            throw new InvalidArgumentException('Widget order is outside supported bounds.');
        }

        if ($widget->cacheTtlSeconds() < 0 || $widget->cacheTtlSeconds() > 86400) {
            throw new InvalidArgumentException('Widget cache TTL is outside supported bounds.');
        }

        $this->slots->get($widget->slot());
        if (isset($this->widgets[$key])) {
            throw new InvalidArgumentException('Widget key is already registered: ' . $key);
        }

        $this->widgets[$key] = new RegisteredWidget($ownerType, $ownerKey, $widget);
    }
}
