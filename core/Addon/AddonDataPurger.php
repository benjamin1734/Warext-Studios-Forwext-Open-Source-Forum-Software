<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

interface AddonDataPurger
{
    public function supports(AddonId $id): bool;

    public function purge(AddonInstallation $installation): void;
}
