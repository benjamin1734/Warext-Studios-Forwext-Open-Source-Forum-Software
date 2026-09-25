<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Queue\QueueJobHandlerRegistry;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Scheduler\SchedulerRegistry;
use Forwext\Core\Search\Lifecycle\SearchContentSourceRegistry;

final readonly class AddonBackendRuntimeIntegrator
{
    private AddonBackendMetadataRegistry $metadata;

    public function __construct(?AddonBackendMetadataRegistry $metadata = null)
    {
        $this->metadata = $metadata ?? new AddonBackendMetadataRegistry();
    }

    public function metadata(): AddonBackendMetadataRegistry
    {
        return $this->metadata;
    }

    public function applyRegistry(
        AddonBackendRegistry $registry,
        RouteCollection $routes,
        QueueJobHandlerRegistry $jobs,
        SchedulerRegistry $scheduler,
        SearchContentSourceRegistry $search,
        NotificationRegistry $notifications,
    ): void {
        $routeProbe = clone $routes;
        $jobProbe = clone $jobs;
        $schedulerProbe = clone $scheduler;
        $searchProbe = clone $search;
        $notificationProbe = clone $notifications;
        $metadataProbe = clone $this->metadata;

        foreach ($registry->all() as $registration) {
            $this->applyInto(
                $registration,
                $routeProbe,
                $jobProbe,
                $schedulerProbe,
                $searchProbe,
                $notificationProbe,
                $metadataProbe,
            );
        }
        foreach ($registry->all() as $registration) {
            $this->applyInto(
                $registration,
                $routes,
                $jobs,
                $scheduler,
                $search,
                $notifications,
                $this->metadata,
            );
        }
    }

    public function apply(
        AddonBackendRegistration $registration,
        RouteCollection $routes,
        QueueJobHandlerRegistry $jobs,
        SchedulerRegistry $scheduler,
        SearchContentSourceRegistry $search,
        NotificationRegistry $notifications,
    ): void {
        $routeProbe = clone $routes;
        $jobProbe = clone $jobs;
        $schedulerProbe = clone $scheduler;
        $searchProbe = clone $search;
        $notificationProbe = clone $notifications;
        $metadataProbe = clone $this->metadata;

        $this->applyInto(
            $registration,
            $routeProbe,
            $jobProbe,
            $schedulerProbe,
            $searchProbe,
            $notificationProbe,
            $metadataProbe,
        );
        $this->applyInto(
            $registration,
            $routes,
            $jobs,
            $scheduler,
            $search,
            $notifications,
            $this->metadata,
        );
    }

    private function applyInto(
        AddonBackendRegistration $registration,
        RouteCollection $routes,
        QueueJobHandlerRegistry $jobs,
        SchedulerRegistry $scheduler,
        SearchContentSourceRegistry $search,
        NotificationRegistry $notifications,
        AddonBackendMetadataRegistry $metadata,
    ): void {
        foreach ($registration->routes() as $route) {
            $routes->add($route);
        }
        foreach ($registration->jobs() as $handler) {
            $jobs->registerAddon($registration->addonId, $handler);
        }
        foreach ($registration->scheduledTasks() as $task) {
            $scheduler->register($task);
        }
        foreach ($registration->searchSources() as $source) {
            $search->register($source);
        }
        foreach ($registration->notifications() as $definition) {
            $notifications->register($definition);
        }
        foreach ($registration->entities() as $definition) {
            $metadata->registerEntity($registration->addonId, $definition);
        }
        foreach ($registration->webhooks() as $definition) {
            $metadata->registerWebhook($registration->addonId, $definition);
        }
        foreach ($registration->contentTypes() as $definition) {
            $metadata->registerContentType($registration->addonId, $definition);
        }
    }
}
