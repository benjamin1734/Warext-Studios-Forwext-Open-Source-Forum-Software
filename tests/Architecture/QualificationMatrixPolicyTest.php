<?php

declare(strict_types=1);

namespace Forwext\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class QualificationMatrixPolicyTest extends TestCase
{
    public function testPhpApiBrowserModuleAndAddonQualificationAreExplicit(): void
    {
        $workflow = $this->read('.github/workflows/qualification-matrix.yml');

        self::assertStringContainsString("php: ['8.4', '8.5']", $workflow);
        self::assertStringContainsString('tests/Unit/App/Web/Api', $workflow);
        self::assertStringContainsString('tests/Unit/App/Web', $workflow);
        self::assertStringContainsString(
            'tests/Unit/Core/Module/FirstPartyModuleRuntimeMiddlewareTest.php',
            $workflow,
        );
        self::assertStringContainsString(
            'tests/Unit/Core/Addon/SampleAddonContractTest.php',
            $workflow,
        );
        self::assertStringContainsString('tests/Unit/Core/Migration', $workflow);
        self::assertStringContainsString('tests/Unit/Core/Update', $workflow);
    }

    public function testDatabaseMatrixRunsCleanInstallUpgradeAndRuntimeModuleToggle(): void
    {
        $workflow = $this->read('.github/workflows/mysql-migration-smoke.yml');

        self::assertStringContainsString('mysql:8.4', $workflow);
        self::assertStringContainsString('mariadb:10.11', $workflow);
        self::assertSame(2, substr_count($workflow, 'smoke-core-migrations.php'));
        self::assertSame(2, substr_count($workflow, 'smoke-post-install-web.php'));
        self::assertSame(2, substr_count($workflow, 'smoke-upgrade-migrations.php'));
        self::assertSame(2, substr_count($workflow, 'smoke-module-runtime-toggle.php'));
    }

    public function testReleaseBuildRemainsARequiredIndependentGate(): void
    {
        $workflow = $this->read('.github/workflows/build-install-package.yml');

        self::assertStringContainsString('Run PHPUnit on PHP 8.4', $workflow);
        self::assertStringContainsString('Run PHPUnit on PHP 8.5', $workflow);
        self::assertStringContainsString('Build cPanel full package', $workflow);
        self::assertStringContainsString('Build differential update package', $workflow);
        self::assertStringContainsString('Verify full and update package integrity', $workflow);
        self::assertStringContainsString('Typecheck and build official Next.js frontend', $workflow);
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read qualification policy file: ' . $relativePath);
        }

        return $contents;
    }
}
