<?php

declare(strict_types=1);

namespace Forwext\Core\Search\DiscoveryUx;

interface GlobalDiscoveryContributor
{
    public function registerDiscovery(GlobalDiscoveryRegistry $registry): void;
}
