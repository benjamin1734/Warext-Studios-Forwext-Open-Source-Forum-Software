<?php

declare(strict_types=1);

namespace Forwext\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class ProductionReleasePolicyTest extends TestCase
{
    public function testProductionHandbookCoversFinalAcceptanceDomains(): void
    {
        $handbook = $this->read('docs/production/README.md');

        foreach ([
            '## Installation',
            '## Administration',
            '## Themes',
            '## First-party modules',
            '## Third-party add-ons',
            '## REST API',
            '## TypeScript SDK',
            '## React UI and Next.js',
            '## Updates and backups',
            '## Security operations',
            '## 1.0.0 final acceptance',
        ] as $section) {
            self::assertStringContainsString($section, $handbook);
        }
    }

    public function testReleaseWorkflowSupportsStableProductionTags(): void
    {
        $workflow = $this->read('.github/workflows/build-install-package.yml');

        self::assertStringContainsString('PRODUCTION_RE=', $workflow);
        self::assertStringContainsString('RELEASE_KIND="production"', $workflow);
        self::assertStringContainsString('["git", "tag", "--list", "v*"]', $workflow);
        self::assertStringContainsString('if [[ "$RELEASE_KIND" != "production" ]]', $workflow);
        self::assertStringContainsString('RELEASE_ARGS+=(--prerelease)', $workflow);
    }

    public function testLicenseNoticeFilesExistForProductionDistribution(): void
    {
        foreach (['LICENSE', 'NOTICE', 'THIRD_PARTY_NOTICES.md'] as $path) {
            self::assertNotSame('', trim($this->read($path)), $path . ' must not be empty.');
        }
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read production release file: ' . $relativePath);
        }

        return $contents;
    }
}
