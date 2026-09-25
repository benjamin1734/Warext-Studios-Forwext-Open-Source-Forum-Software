<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use PHPUnit\Framework\TestCase;

final class AddonSettingManagerSecurityContractTest extends TestCase
{
    public function testSettingMutationsRequireBackendPermissionsAndAuditWhileReadsStaySeparate(): void
    {
        $root = dirname(__DIR__, 4);
        $manager = (string) file_get_contents($root . '/core/Addon/Backend/AddonSettingManager.php');
        $store = (string) file_get_contents($root . '/core/Addon/Backend/DatabaseAddonSettingStore.php');

        self::assertStringContainsString("ACP_PERMISSION = 'acp.access'", $manager);
        self::assertStringContainsString("MANAGE_PERMISSION = 'addon.manage'", $manager);
        self::assertStringContainsString('$this->audit->mutate', $manager);
        self::assertStringContainsString("'addon.setting.save'", $manager);
        self::assertStringContainsString("'addon.setting.reset'", $manager);
        self::assertStringContainsString('public function effective(', $manager);
        self::assertStringContainsString('requiresTransaction', $store);
        self::assertStringNotContainsString('PermissionAuthorizer', $store);
    }
}
