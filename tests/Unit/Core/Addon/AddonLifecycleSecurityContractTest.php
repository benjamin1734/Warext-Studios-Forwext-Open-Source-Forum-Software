<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use PHPUnit\Framework\TestCase;

final class AddonLifecycleSecurityContractTest extends TestCase
{
    public function testLifecycleUsesCommonPermissionsAuditAndFailClosedPurgePolicy(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/core/Addon/AddonLifecycleService.php');
        $inspector = (string) file_get_contents($root . '/core/Addon/AddonPackageInspector.php');

        self::assertStringContainsString("ACP_PERMISSION = 'acp.access'", $service);
        self::assertStringContainsString("MANAGE_PERMISSION = 'addon.manage'", $service);
        self::assertStringContainsString('$this->audit->mutate', $service);
        self::assertStringContainsString('Add-on must be disabled before uninstall.', $service);
        self::assertStringContainsString('PurgeSupported', $service);
        self::assertStringContainsString('$this->dataPurger->supports', $service);
        self::assertStringContainsString('outside the configured add-on root', $inspector);
        self::assertStringContainsString('symbolic links', $inspector);
        self::assertStringContainsString('package path must match its Vendor/AddOn id', $inspector);
    }
}
