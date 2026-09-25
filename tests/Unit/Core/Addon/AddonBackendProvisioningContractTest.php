<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use PHPUnit\Framework\TestCase;

final class AddonBackendProvisioningContractTest extends TestCase
{
    public function testProvisioningUsesSharedMigrationEngineCatalogAndExtensionAwareAcp(): void
    {
        $root = dirname(__DIR__, 4);
        $provisioner = (string) file_get_contents($root . '/core/Addon/Backend/AddonBackendProvisioner.php');
        $catalog = (string) file_get_contents(
            $root . '/core/Addon/Backend/DatabaseAddonBackendCatalogSynchronizer.php',
        );
        $navigation = (string) file_get_contents(
            $root . '/core/Admin/Navigation/AdminNavigationRegistry.php',
        );
        $registration = (string) file_get_contents(
            $root . '/core/Addon/Backend/AddonBackendRegistration.php',
        );

        self::assertStringContainsString('$this->migrations->migrate(', $provisioner);
        self::assertStringContainsString('$this->catalog->synchronize(', $provisioner);
        self::assertStringContainsString('forwext_permissions', $catalog);
        self::assertStringContainsString('value type cannot change', $catalog);
        self::assertStringContainsString('withExtensions(', $navigation);
        self::assertStringContainsString('/admin/addons/', $registration);
        self::assertStringContainsString('admin.' . "' . $this->namespace->prefix()", $registration);
    }

    public function testSettingStoreMutationsDeclareTransactionRequirement(): void
    {
        $root = dirname(__DIR__, 4);
        $store = (string) file_get_contents($root . '/core/Addon/Backend/DatabaseAddonSettingStore.php');

        self::assertGreaterThanOrEqual(2, substr_count($store, 'requiresTransaction:true'));
    }
}
