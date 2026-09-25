<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use Forwext\Core\Addon\AddonRepository;
use Forwext\Core\Addon\AddonState;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Queue\QueueJobHandlerRegistry;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Scheduler\SchedulerRegistry;
use Forwext\Core\Search\Lifecycle\SearchContentSourceRegistry;

final readonly class AddonBackendRuntimeActivator
{
    public function __construct(
        private AddonRepository $installations,
        private AddonBackendRuntimeIntegrator $integrator,
    ) {
    }

    /**
     * Applies backend capabilities only for add-ons whose persisted lifecycle state is enabled.
     *
     * @return list<string> Canonical enabled add-on ids that were applied.
     */
    public function applyEnabledRegistry(
        AddonBackendRegistry $discovered,
        RouteCollection $routes,
        QueueJobHandlerRegistry $jobs,
        SchedulerRegistry $scheduler,
        SearchContentSourceRegistry $search,
        NotificationRegistry $notifications,
    ): array {
        $enabled = new AddonBackendRegistry();
        $enabledIds = [];

        foreach ($discovered->all() as $registration) {
            $installation = $this->installations->find($registration->addonId);
            if ($installation === null || $installation->state !== AddonState::Enabled) {
                continue;
            }

            $enabled->register($registration);
            $enabledIds[] = $registration->addonId->value();
        }

        $this->integrator->applyRegistry(
            $enabled,
            $routes,
            $jobs,
            $scheduler,
            $search,
            $notifications,
        );

        return $enabledIds;
    }
}
