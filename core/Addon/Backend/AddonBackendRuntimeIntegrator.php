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

        foreach ($registry->all() as $registration) {
            $this->applyInto(
                $registration,
                $routeProbe,
                $jobProbe,
                $schedulerProbe,
                $searchProbe,
                $notificationProbe,
            );
        }
        foreach ($registry->all() as $registration) {
            $this->applyInto($registration, $routes, $jobs, $scheduler, $search, $notifications);
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

        $this->applyInto($registration, $routeProbe, $jobProbe, $schedulerProbe, $searchProbe, $notificationProbe);
        $this->applyInto($registration, $routes, $jobs, $scheduler, $search, $notifications);
    }

    private function applyInto(
        AddonBackendRegistration $registration,
        RouteCollection $routes,
        QueueJobHandlerRegistry $jobs,
        SchedulerRegistry $scheduler,
        SearchContentSourceRegistry $search,
        NotificationRegistry $notifications,
    ): void {
        foreach ($registration->routes() as $route) $routes->add($route);
        foreach ($registration->jobs() as $handler) $jobs->registerAddon($registration->addonId, $handler);
        foreach ($registration->scheduledTasks() as $task) $scheduler->register($task);
        foreach ($registration->searchSources() as $source) $search->register($source);
        foreach ($registration->notifications() as $definition) $notifications->register($definition);
    }
}
