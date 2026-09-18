<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Logging;

use Forwext\Core\Logging\RuntimeFailureReporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RuntimeFailureReporterTest extends TestCase
{
    private string $directory;
    private string $logPath;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-runtime-log-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
        $this->logPath = $this->directory . '/runtime.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        @rmdir($this->directory);
    }

    public function testReporterLogsCorrelationWithoutQueryOrSecrets(): void
    {
        $failure = new RuntimeException(
            "bootstrap failed password=super-secret token=abc123\nsecond line",
        );

        $reference = (new RuntimeFailureReporter($this->logPath))->report(
            $failure,
            '/account?token=should-not-be-logged',
        );

        self::assertMatchesRegularExpression('/^[A-F0-9]{12}$/D', $reference);
        $contents = (string) file_get_contents($this->logPath);

        self::assertStringContainsString('runtime_ref=' . $reference, $contents);
        self::assertStringContainsString('path=/account', $contents);
        self::assertStringNotContainsString('should-not-be-logged', $contents);
        self::assertStringNotContainsString('super-secret', $contents);
        self::assertStringNotContainsString('abc123', $contents);
        self::assertStringContainsString('password=[REDACTED]', $contents);
        self::assertStringContainsString('token=[REDACTED]', $contents);
    }
}
