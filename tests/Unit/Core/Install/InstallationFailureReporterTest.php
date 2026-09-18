<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Install;

use Forwext\Core\Install\InstallationFailureReporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstallationFailureReporterTest extends TestCase
{
    private string $directory;
    private string $logPath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-install-log-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        $this->logPath = $this->directory . '/install.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        @rmdir($this->directory);
    }

    public function testReportIncludesReferenceAndRootCauseWithoutPassword(): void
    {
        $root = new RuntimeException('SQLSTATE test password=super-secret');
        $top = new RuntimeException('Migration core:test failed.', previous: $root);

        $message = (new InstallationFailureReporter($this->logPath))->report($top);

        self::assertStringContainsString('Kurulum başarısız [', $message);
        self::assertStringContainsString('Migration core:test failed.', $message);
        self::assertStringContainsString('Alt neden:', $message);
        self::assertStringNotContainsString('super-secret', $message);

        $log = (string) file_get_contents($this->logPath);
        self::assertStringContainsString('install_ref=', $log);
        self::assertStringContainsString('Migration core:test failed.', $log);
        self::assertStringNotContainsString('super-secret', $log);
    }
}
