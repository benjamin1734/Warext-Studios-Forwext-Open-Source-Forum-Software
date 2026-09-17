<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Navigation;

use InvalidArgumentException;

final class NavigationRegistry
{
    /** @var array<string, NavigationItem> */
    private array $items = [];

    /** @param iterable<NavigationContributor> $contributors */
    public static function withCoreDefaults(iterable $contributors = []): self
    {
        $registry = new self();
        $registry->register(new NavigationItem('search', 'Ara', '/search', 100));
        $registry->register(new NavigationItem('members', 'Üyeler', '/members', 200));
        $registry->register(new NavigationItem('members.online', 'Çevrimiçi', '/members/online', 210));
        $registry->register(new NavigationItem('forum.stats', 'İstatistikler', '/stats', 300));

        foreach ($contributors as $contributor) {
            $contributor->registerNavigation($registry);
        }

        return $registry;
    }

    public function register(NavigationItem $item): void
    {
        if (isset($this->items[$item->key])) {
            throw new InvalidArgumentException('Navigation key is already registered: ' . $item->key);
        }
        $this->items[$item->key] = $item;
    }

    public function registerModule(string $moduleKey, NavigationItem $item): void
    {
        if ($item->moduleKey !== $moduleKey) {
            throw new InvalidArgumentException('Module navigation item must declare its owning module key.');
        }
        $this->register($item);
    }

    /** @return list<NavigationItem> */
    public function visible(bool $authenticated): array
    {
        $items = array_values(array_filter(
            $this->items,
            static fn (NavigationItem $item): bool => $authenticated
                || $item->audience === NavigationAudience::Public,
        ));
        usort(
            $items,
            static fn (NavigationItem $left, NavigationItem $right): int =>
                [$left->order, $left->label, $left->key] <=> [$right->order, $right->label, $right->key],
        );

        return $items;
    }
}
