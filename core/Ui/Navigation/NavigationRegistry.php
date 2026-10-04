<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Navigation;

use InvalidArgumentException;

final class NavigationRegistry
{
    /** @var array<string, NavigationItem> */
    private array $items = [];

    /**
     * @param iterable<NavigationContributor> $contributors
     * @param array<string,mixed> $managed
     */
    public static function withCoreDefaults(iterable $contributors = [], array $managed = []): self
    {
        $registry = new self();
        $registry->register(new NavigationItem(
            'forums', 'Forumlar', '/forums', 50,
            placement: NavigationPlacement::Primary,
        ));
        $registry->register(new NavigationItem(
            'search', 'Ara', '/search', 100,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'servers', 'Sunucular', '/servers', 190,
            placement: NavigationPlacement::Primary,
        ));
        $registry->register(new NavigationItem(
            'members', 'Üyeler', '/members', 200,
            placement: NavigationPlacement::Primary,
        ));
        $registry->register(new NavigationItem(
            'members.online', 'Çevrimiçi', '/members/online', 210,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'members.staff', 'Yetkili Ekip', '/members/staff', 220,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'portfolio', 'Portfolyo', '/portfolio', 230,
            placement: NavigationPlacement::Primary,
        ));
        $registry->register(new NavigationItem(
            'giveaways',
            'Çekilişler',
            '/giveaways',
            240,
            NavigationAudience::Member,
            placement: NavigationPlacement::More,
        ));
        $registry->register(new NavigationItem(
            'marketplace', 'Marketplace', '/marketplace', 180,
            placement: NavigationPlacement::Primary,
        ));
        $registry->register(new NavigationItem(
            'faq', 'SSS', '/faq', 250,
            placement: NavigationPlacement::Primary,
        ));
        $registry->register(new NavigationItem(
            'account.own',
            'Hesabım',
            '/account',
            255,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'preferences.own',
            'Tercihler ve gizlilik',
            '/account/preferences',
            255,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'profile.settings.own',
            'Profil ve kimlik',
            '/account/profile',
            256,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'sessions.own',
            'Oturumlar',
            '/account/sessions',
            258,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'bookmarks.own',
            'Kaydedilenler',
            '/account/bookmarks',
            262,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'relationships.own',
            'Takip ve engelleme',
            '/account/relationships',
            263,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'watched.threads.own',
            'Takip edilen konular',
            '/account/watched/threads',
            264,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'watched.forums.own',
            'Takip edilen forumlar',
            '/account/watched/forums',
            265,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'notification-settings.own',
            'Bildirim ayarları',
            '/account/notification-settings',
            269,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'presence.own',
            'Çevrimiçi görünürlük',
            '/account/presence',
            269,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'security.own',
            'Güvenlik',
            '/account/security',
            256,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'conversations.own',
            'Mesajlar',
            '/account/conversations',
            257,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'referrals.own',
            'Davetlerim',
            '/account/referrals',
            260,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'subscriptions.own',
            'Upgrades',
            '/account/upgrades',
            265,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'notifications.own',
            'Bildirimler',
            '/account/notifications',
            268,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'bugs.mine',
            'Hata Bildirimlerim',
            '/bugs',
            270,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));
        $registry->register(new NavigationItem(
            'forum.stats',
            'İstatistikler',
            '/stats',
            300,
            NavigationAudience::Member,
            placement: NavigationPlacement::Utility,
        ));

        foreach ($contributors as $contributor) {
            $contributor->registerNavigation($registry);
        }

        $registry->applyManagedConfiguration($managed);

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

    public function registerAddon(string $addonKey, NavigationItem $item): void
    {
        if ($item->addonKey !== $addonKey || !str_starts_with($item->key, $addonKey . '.')) {
            throw new InvalidArgumentException('Add-on navigation item must declare and use its add-on namespace.');
        }
        $this->register($item);
    }

    /** @return list<NavigationItem> */
    public function all(): array
    {
        $items = array_values($this->items);
        self::sort($items);

        return $items;
    }

    /** @return list<NavigationItem> */
    public function visible(bool $authenticated): array
    {
        $items = array_values(array_filter(
            $this->items,
            static fn (NavigationItem $item): bool => $authenticated
                || $item->audience === NavigationAudience::Public,
        ));
        self::sort($items);

        return $items;
    }

    /** @param array<string,mixed> $managed */
    private function applyManagedConfiguration(array $managed): void
    {
        foreach ($managed as $key => $definition) {
            if (!is_string($key) || !is_array($definition)) {
                continue;
            }

            $existing = $this->items[$key] ?? null;
            if ($existing === null && !str_starts_with($key, 'custom.')) {
                continue;
            }

            $enabled = $definition['enabled'] ?? true;
            if (!is_bool($enabled)) {
                continue;
            }
            if (!$enabled) {
                if ($existing !== null) {
                    unset($this->items[$key]);
                }
                continue;
            }

            $label = $definition['label'] ?? $existing?->label;
            $path = $definition['path'] ?? $existing?->path;
            $order = $definition['order'] ?? $existing?->order ?? 500;
            $audienceRaw = $definition['audience'] ?? $existing?->audience->value ?? NavigationAudience::Public->value;
            $placementRaw = $definition['placement'] ?? $existing?->placement->value ?? NavigationPlacement::More->value;

            if (
                !is_string($label)
                || !is_string($path)
                || !is_int($order)
                || !is_string($audienceRaw)
                || !is_string($placementRaw)
            ) {
                continue;
            }

            $audience = NavigationAudience::tryFrom($audienceRaw);
            $placement = NavigationPlacement::tryFrom($placementRaw);
            if ($audience === null || $placement === null) {
                continue;
            }

            try {
                $this->items[$key] = new NavigationItem(
                    $key,
                    $label,
                    $path,
                    $order,
                    $audience,
                    $existing?->moduleKey,
                    $existing?->addonKey,
                    $placement,
                );
            } catch (InvalidArgumentException) {
                // Corrupt generated navigation configuration must not take down the public site.
                continue;
            }
        }
    }

    /** @param list<NavigationItem> $items */
    private static function sort(array &$items): void
    {
        usort(
            $items,
            static fn (NavigationItem $left, NavigationItem $right): int =>
                [$left->order, $left->label, $left->key] <=> [$right->order, $right->label, $right->key],
        );
    }
}
