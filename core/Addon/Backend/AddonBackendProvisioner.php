<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use Forwext\Core\Migration\MigrationEngine;
use Forwext\Core\Migration\MigrationRunReport;

final readonly class AddonBackendProvisioner
{
    public function __construct(
        private MigrationEngine $migrations,
        private DatabaseAddonBackendCatalogSynchronizer $catalog,
    ) {
    }

    public function provision(AddonBackendRegistration $registration): MigrationRunReport
    {
        $report = $this->migrations->migrate($registration->migrations());
        $this->catalog->synchronize($registration);

        return $report;
    }

    public function provisionRegistry(AddonBackendRegistry $registry): MigrationRunReport
    {
        $report = $this->migrations->migrate($registry->migrations());
        foreach ($registry->all() as $registration) {
            $this->catalog->synchronize($registration);
        }

        return $report;
    }
}
