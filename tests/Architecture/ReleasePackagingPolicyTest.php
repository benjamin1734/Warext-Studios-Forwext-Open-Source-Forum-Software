<?php

declare(strict_types=1);

namespace Forwext\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class ReleasePackagingPolicyTest extends TestCase
{
    public function testBindingFullAndUpdateNamesAreProduced(): void
    {
        $workflow = $this->read('.github/workflows/build-install-package.yml');
        $policy = $this->read('docs/release/versioning-policy.md');

        self::assertStringContainsString('forwext-v${VERSION}-full.zip', $workflow);
        self::assertStringContainsString('forwext-v${VERSION}-update.zip', $workflow);
        self::assertStringContainsString('forwext-vX.Y.Z-full.zip', $policy);
        self::assertStringContainsString('forwext-vX.Y.Z-update.zip', $policy);
    }

    public function testUpdateManifestCarriesTheRequiredUpgradeContract(): void
    {
        $workflow = $this->read('.github/workflows/build-install-package.yml');

        foreach ([
            '"source_version"',
            '"target_version"',
            '"add"',
            '"replace"',
            '"delete"',
            '"migrations"',
            '"rebuild"',
            '"checksum"',
        ] as $requiredField) {
            self::assertStringContainsString($requiredField, $workflow, $requiredField . ' missing from update manifest.');
        }

        self::assertStringContainsString('update-manifest.json', $workflow);
        self::assertStringContainsString('sha256', $workflow);
    }

    public function testLegacyInstallPackageCanOnlyBeReadAsPredecessorCompatibility(): void
    {
        $workflow = $this->read('.github/workflows/build-install-package.yml');

        self::assertStringContainsString("--pattern 'forwext-*-install.zip'", $workflow);
        self::assertStringContainsString('predates the full.zip naming contract', $workflow);
        self::assertStringNotContainsString('zip -rq "forwext-${VERSION}-install.zip"', $workflow);
    }

    public function testPreservedHtaccessFilesAreRepairedForBothSupportedDocumentRoots(): void
    {
        $workflow = $this->read('.github/workflows/build-install-package.yml');
        $installer = $this->read('core/Install/InstallationService.php');
        $runtime = $this->read('public/index.php');

        self::assertStringContainsString("--exclude '/.htaccess'", $workflow);
        self::assertStringContainsString("--exclude 'public/.htaccess'", $workflow);
        self::assertStringContainsString("$this->projectRoot . '/.htaccess'", $installer);
        self::assertStringContainsString("$this->projectRoot . '/public/.htaccess'", $installer);
        self::assertStringContainsString("$root . '/.htaccess'", $runtime);
        self::assertStringContainsString("$root . '/public/.htaccess'", $runtime);
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read release policy file: ' . $relativePath);
        }

        return $contents;
    }
}
