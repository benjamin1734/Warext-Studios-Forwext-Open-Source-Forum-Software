<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use Forwext\Core\Addon\AddonRepository;
use Forwext\Core\Addon\AddonState;

final readonly class AddonUiRuntimeActivator
{
    public function __construct(private AddonRepository $installations)
    {
    }

    public function enabledRegistry(AddonUiRegistry $discovered): AddonUiRegistry
    {
        $enabled = new AddonUiRegistry();

        foreach ($discovered->all() as $registration) {
            $installation = $this->installations->find($registration->addonId);
            if ($installation === null || $installation->state !== AddonState::Enabled) {
                continue;
            }

            $enabled->register($registration);
        }

        return $enabled;
    }
}
