<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Navigation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Admin\Integration\GeneratedConfigStore;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Ui\Navigation\NavigationAudience;
use Forwext\Core\Ui\Navigation\NavigationItem;
use Forwext\Core\Ui\Navigation\NavigationPlacement;
use Forwext\Core\Ui\Navigation\NavigationRegistry;
use Forwext\Core\Ui\Navigation\NavigationRuntime;
use InvalidArgumentException;

final readonly class PublicNavigationService
{
    private const MANAGEABLE_CORE_KEYS = [
        'forums',
        'marketplace',
        'members',
        'portfolio',
        'faq',
        'giveaways',
    ];

    public function __construct(
        private GeneratedConfigStore $generated,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ) {
    }

    /** @return list<ManagedPublicNavigationItem> */
    public function snapshot(EntityId $actor): array
    {
        $this->requireManage($actor);
        $managed = $this->managedConfig();
        $baseline = [];
        foreach (NavigationRegistry::withCoreDefaults()->all() as $item) {
            if (in_array($item->key, self::MANAGEABLE_CORE_KEYS, true)) {
                $baseline[$item->key] = $item;
            }
        }

        $rows = [];
        foreach ($baseline as $key => $item) {
            $rows[] = $this->state($item, $managed[$key] ?? null, false);
        }
        foreach ($managed as $key => $definition) {
            if (!is_string($key) || !str_starts_with($key, 'custom.') || !is_array($definition)) {
                continue;
            }
            $custom = $this->customItem($key, $definition);
            if ($custom !== null) {
                $rows[] = $this->state($custom, $definition, true);
            }
        }

        usort(
            $rows,
            static fn (ManagedPublicNavigationItem $left, ManagedPublicNavigationItem $right): int =>
                [$left->order, $left->label, $left->key] <=> [$right->order, $right->label, $right->key],
        );

        return $rows;
    }

    public function save(
        EntityId $actor,
        string $key,
        string $label,
        string $path,
        int $order,
        NavigationAudience $audience,
        NavigationPlacement $placement,
        bool $enabled,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $key = trim($key);
        if (!$this->isManageableKey($key)) {
            throw new InvalidArgumentException('Navigation item is not manageable.');
        }
        if ($placement === NavigationPlacement::Utility) {
            throw new InvalidArgumentException('Managed public links may only use primary or overflow placement.');
        }

        $item = new NavigationItem(
            $key,
            trim($label),
            trim($path),
            $order,
            $audience,
            placement: $placement,
        );
        $managed = $this->managedConfig();
        $before = $managed[$key] ?? null;
        $after = [
            'label' => $item->label,
            'path' => $item->path,
            'order' => $item->order,
            'audience' => $item->audience->value,
            'placement' => $item->placement->value,
            'enabled' => $enabled,
        ];
        $managed[$key] = $after;

        $event = $this->event(
            $actor,
            'navigation.item.update',
            $key,
            ['definition' => $before],
            ['definition' => $after],
            $requestId,
            $at,
        );
        $this->audit->mutate(
            $event,
            fn (): mixed => $this->generated->set('navigation.items', $managed),
        );
        NavigationRuntime::configure($managed);
    }

    public function createCustom(
        EntityId $actor,
        string $suffix,
        string $label,
        string $path,
        int $order,
        NavigationAudience $audience,
        NavigationPlacement $placement,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): string {
        $this->requireManage($actor);
        $suffix = strtolower(trim($suffix));
        if (preg_match('/^[a-z][a-z0-9-]{1,60}$/D', $suffix) !== 1) {
            throw new InvalidArgumentException('Custom navigation key is invalid.');
        }

        $key = 'custom.' . $suffix;
        $managed = $this->managedConfig();
        if (array_key_exists($key, $managed)) {
            throw new InvalidArgumentException('Custom navigation key already exists.');
        }

        $this->save(
            $actor,
            $key,
            $label,
            $path,
            $order,
            $audience,
            $placement,
            true,
            $requestId,
            $at,
        );

        return $key;
    }

    public function resetItem(
        EntityId $actor,
        string $key,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        if (!$this->isManageableKey($key)) {
            throw new InvalidArgumentException('Navigation item is not manageable.');
        }

        $managed = $this->managedConfig();
        $before = $managed[$key] ?? null;
        unset($managed[$key]);

        $event = $this->event(
            $actor,
            'navigation.item.reset',
            $key,
            ['definition' => $before],
            ['definition' => null],
            $requestId,
            $at,
        );
        $this->audit->mutate(
            $event,
            fn (): mixed => $this->generated->set('navigation.items', $managed),
        );
        NavigationRuntime::configure($managed);
    }

    public function deleteCustom(
        EntityId $actor,
        string $key,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        if (!str_starts_with($key, 'custom.')) {
            throw new InvalidArgumentException('Only custom navigation items may be deleted.');
        }

        $managed = $this->managedConfig();
        if (!array_key_exists($key, $managed)) {
            throw new InvalidArgumentException('Custom navigation item does not exist.');
        }
        $before = $managed[$key];
        unset($managed[$key]);

        $event = $this->event(
            $actor,
            'navigation.item.delete',
            $key,
            ['definition' => $before],
            ['deleted' => true],
            $requestId,
            $at,
        );
        $this->audit->mutate(
            $event,
            fn (): mixed => $this->generated->set('navigation.items', $managed),
        );
        NavigationRuntime::configure($managed);
    }

    /** @return array<string,mixed> */
    public function managedConfig(): array
    {
        $all = $this->generated->all();
        $navigation = $all['navigation'] ?? null;
        if (!is_array($navigation)) {
            return [];
        }
        $items = $navigation['items'] ?? null;

        return is_array($items) && !array_is_list($items) ? $items : [];
    }

    private function requireManage(EntityId $actor): void
    {
        foreach (['acp.access', 'acp.manage'] as $permission) {
            $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
            if (!$decision->isAllowed()) {
                throw new PermissionDeniedException($decision);
            }
        }
    }

    private function isManageableKey(string $key): bool
    {
        return in_array($key, self::MANAGEABLE_CORE_KEYS, true)
            || str_starts_with($key, 'custom.');
    }

    /** @param array<string,mixed>|null $definition */
    private function state(
        NavigationItem $base,
        ?array $definition,
        bool $custom,
    ): ManagedPublicNavigationItem {
        $definition ??= [];
        $label = is_string($definition['label'] ?? null) ? $definition['label'] : $base->label;
        $path = is_string($definition['path'] ?? null) ? $definition['path'] : $base->path;
        $order = is_int($definition['order'] ?? null) ? $definition['order'] : $base->order;
        $audience = is_string($definition['audience'] ?? null)
            ? NavigationAudience::tryFrom($definition['audience'])
            : null;
        $placement = is_string($definition['placement'] ?? null)
            ? NavigationPlacement::tryFrom($definition['placement'])
            : null;
        $enabled = is_bool($definition['enabled'] ?? null) ? $definition['enabled'] : true;

        return new ManagedPublicNavigationItem(
            $base->key,
            $label,
            $path,
            $order,
            $audience ?? $base->audience,
            $placement ?? $base->placement,
            $enabled,
            $custom,
        );
    }

    /** @param array<string,mixed> $definition */
    private function customItem(string $key, array $definition): ?NavigationItem
    {
        $label = $definition['label'] ?? null;
        $path = $definition['path'] ?? null;
        $order = $definition['order'] ?? 500;
        $audience = is_string($definition['audience'] ?? null)
            ? NavigationAudience::tryFrom($definition['audience'])
            : NavigationAudience::Public;
        $placement = is_string($definition['placement'] ?? null)
            ? NavigationPlacement::tryFrom($definition['placement'])
            : NavigationPlacement::More;

        if (!is_string($label) || !is_string($path) || !is_int($order) || $audience === null || $placement === null) {
            return null;
        }
        try {
            return new NavigationItem($key, $label, $path, $order, $audience, placement: $placement);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function event(
        EntityId $actor,
        string $action,
        string $targetId,
        array $before,
        array $after,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): AuditEvent {
        return new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString($action),
            'navigation.item',
            $targetId,
            null,
            $action,
            $requestId,
            $before,
            $after,
            $at->setTimezone(new DateTimeZone('UTC')),
        );
    }
}
