<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Install;

use Forwext\Core\Install\InstallationPreflight;
use PHPUnit\Framework\TestCase;

final class InstallationPreflightTest extends TestCase
{
    public function testPreflightContainsCpanelCriticalChecks(): void
    {
        $preflight = new InstallationPreflight(dirname(__DIR__, 4));
        $checks = $preflight->checks();
        $keys = array_column($checks, 'key');

        self::assertContains('php', $keys);
        self::assertContains('openssl', $keys);
        self::assertContains('pdo_mysql', $keys);
        self::assertContains('autoload', $keys);
        self::assertContains('config_writable', $keys);
        self::assertContains('storage_writable', $keys);

        foreach ($checks as $check) {
            self::assertTrue($check['required']);
            self::assertNotSame('', $check['label']);
            self::assertNotSame('', $check['detail']);
        }
    }
}
