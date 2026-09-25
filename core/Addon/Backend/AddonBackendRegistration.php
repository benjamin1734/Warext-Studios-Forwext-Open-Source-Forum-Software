<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Admin\Navigation\AdminNavigationItem;
use Forwext\Core\Domain\Access\Permission\PermissionCatalogEntry;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationScope;
use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Queue\QueueJobHandler;
use Forwext\Core\Routing\Route;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use InvalidArgumentException;

final class AddonBackendRegistration
{
    private AddonBackendNamespace $namespace;

    /** @var array<string,Migration> */
    private array $migrations = [];
    /** @var array<string,AddonEntityDefinition> */
    private array $entities = [];
    /** @var array<string,Route> */
    private array $routes = [];
    /** @var array<string,PermissionCatalogEntry> */
    private array $permissions = [];
    /** @var array<string,AddonSettingDefinition> */
    private array $settings = [];
    /** @var array<string,AdminNavigationItem> */
    private array $adminItems = [];
    /** @var array<string,QueueJobHandler> */
    private array $jobs = [];
    /** @var array<string,ScheduledTask> */
    private array $tasks = [];
    /** @var array<string,SearchContentSource> */
    private array $searchSources = [];
    /** @var array<string,NotificationDefinition> */
    private array $notifications = [];
    /** @var array<string,AddonWebhookDefinition> */
    private array $webhooks = [];
    /** @var array<string,AddonContentTypeDefinition> */
    private array $contentTypes = [];

    public function __construct(public readonly AddonId $addonId)
    {
        $this->namespace = AddonBackendNamespace::fromAddonId($addonId);
    }

    public function namespace(): AddonBackendNamespace
    {
        return $this->namespace;
    }

    public function migration(Migration $migration): self
    {
        $owner = $migration->owner();
        if ($owner->scope !== MigrationScope::Addon || $owner->name !== $this->addonId->value()) {
            throw new InvalidArgumentException('Add-on migration owner must match the backend registration owner.');
        }
        $key = $migration->id()->value();
        $this->put($this->migrations, $key, $migration, 'migration');
        return $this;
    }

    public function entity(AddonEntityDefinition $definition): self
    {
        $this->namespace->assertOwned($definition->key, 'Add-on entity key');
        $this->put($this->entities, $definition->key, $definition, 'entity');
        return $this;
    }

    public function route(Route $route): self
    {
        $this->namespace->assertOwned($route->name(), 'Add-on route name');
        $this->put($this->routes, $route->name(), $route, 'route');
        return $this;
    }

    public function permission(PermissionCatalogEntry $permission): self
    {
        $key = $permission->key()->value();
        $this->namespace->assertOwned($key, 'Add-on permission');
        $this->put($this->permissions, $key, $permission, 'permission');
        return $this;
    }

    public function setting(AddonSettingDefinition $setting): self
    {
        $this->namespace->assertOwned($setting->key, 'Add-on setting');
        $this->put($this->settings, $setting->key, $setting, 'setting');
        return $this;
    }

    public function adminNavigation(AdminNavigationItem $item): self
    {
        $expectedKey = 'admin.' . $this->namespace->prefix() . '.';
        $expectedPath = '/admin/addons/' . strtolower($this->addonId->vendor())
            . '/' . strtolower($this->addonId->name());
        if (!str_starts_with($item->key, $expectedKey)
            || ($item->path !== $expectedPath && !str_starts_with($item->path, $expectedPath . '/'))
        ) {
            throw new InvalidArgumentException('Add-on ACP navigation must use its admin key and path namespace.');
        }
        $this->put($this->adminItems, $item->key, $item, 'ACP navigation');
        return $this;
    }

    public function job(QueueJobHandler $handler): self
    {
        $this->namespace->assertOwned($handler->jobType(), 'Add-on job type');
        $this->put($this->jobs, $handler->jobType(), $handler, 'job');
        return $this;
    }

    public function scheduledTask(ScheduledTask $task): self
    {
        $this->namespace->assertOwned($task->name, 'Add-on scheduled task name');
        $this->namespace->assertOwned($task->jobType, 'Add-on scheduled task job type');
        $this->put($this->tasks, $task->name, $task, 'scheduled task');
        return $this;
    }

    public function searchSource(SearchContentSource $source): self
    {
        $type = $source->documentType();
        $this->namespace->assertOwned($type, 'Add-on search document type');
        $this->put($this->searchSources, $type, $source, 'search source');
        return $this;
    }

    public function notification(NotificationDefinition $definition): self
    {
        $this->namespace->assertOwned($definition->typeKey, 'Add-on notification type');
        $this->namespace->assertOwned($definition->categoryKey, 'Add-on notification category');
        $this->put($this->notifications, $definition->typeKey, $definition, 'notification');
        return $this;
    }

    public function webhook(AddonWebhookDefinition $definition): self
    {
        $this->namespace->assertOwned($definition->key, 'Add-on webhook');
        $this->put($this->webhooks, $definition->key, $definition, 'webhook');
        return $this;
    }

    public function contentType(AddonContentTypeDefinition $definition): self
    {
        $this->namespace->assertOwned($definition->key, 'Add-on content type');
        $this->put($this->contentTypes, $definition->key, $definition, 'content type');
        return $this;
    }

    /** @return list<Migration> */
    public function migrations(): array { return $this->values($this->migrations); }
    /** @return list<AddonEntityDefinition> */
    public function entities(): array { return $this->values($this->entities); }
    /** @return list<Route> */
    public function routes(): array { return $this->values($this->routes); }
    /** @return list<PermissionCatalogEntry> */
    public function permissions(): array { return $this->values($this->permissions); }
    /** @return list<AddonSettingDefinition> */
    public function settings(): array { return $this->values($this->settings); }
    public function settingDefinition(string $key): ?AddonSettingDefinition
    {
        return $this->settings[$key] ?? null;
    }
    /** @return list<AdminNavigationItem> */
    public function adminItems(): array { return $this->values($this->adminItems); }
    /** @return list<QueueJobHandler> */
    public function jobs(): array { return $this->values($this->jobs); }
    /** @return list<ScheduledTask> */
    public function scheduledTasks(): array { return $this->values($this->tasks); }
    /** @return list<SearchContentSource> */
    public function searchSources(): array { return $this->values($this->searchSources); }
    /** @return list<NotificationDefinition> */
    public function notifications(): array { return $this->values($this->notifications); }
    /** @return list<AddonWebhookDefinition> */
    public function webhooks(): array { return $this->values($this->webhooks); }
    /** @return list<AddonContentTypeDefinition> */
    public function contentTypes(): array { return $this->values($this->contentTypes); }

    /** @template T @param array<string,T> $items @param T $value */
    private function put(array &$items, string $key, mixed $value, string $label): void
    {
        if (isset($items[$key])) {
            throw new InvalidArgumentException('Duplicate add-on backend ' . $label . ': ' . $key);
        }
        $items[$key] = $value;
    }

    /** @template T @param array<string,T> $items @return list<T> */
    private function values(array $items): array
    {
        ksort($items, SORT_STRING);
        return array_values($items);
    }
}
