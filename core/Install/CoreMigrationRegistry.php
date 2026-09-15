<?php

declare(strict_types=1);

namespace Forwext\Core\Install;

use Forwext\Core\Migration\Migration;
use Forwext\Database\Migrations\Core\CreateAuthenticationRuntimeTables;
use Forwext\Database\Migrations\Core\CreateInfrastructureDriverTables;
use Forwext\Database\Migrations\Core\CreateMfaDeviceSecurityTables;
use Forwext\Database\Migrations\Core\CreateQueueSchedulerRealtimeTables;
use Forwext\Database\Migrations\Core\CreateRegistrationSecurityTables;
use Forwext\Database\Migrations\Core\CreateSearchIndexTables;
use Forwext\Database\Migrations\Core\CreateUserDomainTables;

final class CoreMigrationRegistry
{
    /** @return list<Migration> */
    public static function all(): array
    {
        return [
            new CreateInfrastructureDriverTables(),
            new CreateQueueSchedulerRealtimeTables(),
            new CreateSearchIndexTables(),
            new CreateUserDomainTables(),
            new CreateRegistrationSecurityTables(),
            new CreateAuthenticationRuntimeTables(),
            new CreateMfaDeviceSecurityTables(),
        ];
    }
}
