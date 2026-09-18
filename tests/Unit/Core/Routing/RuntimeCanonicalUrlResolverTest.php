<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Routing;

use Forwext\Core\Routing\RuntimeCanonicalUrlResolver;
use PHPUnit\Framework\TestCase;

final class RuntimeCanonicalUrlResolverTest extends TestCase
{
    public function testRootDeploymentKeepsConfiguredCanonicalUrl(): void
    {
        self::assertSame(
            'https://forum.example',
            RuntimeCanonicalUrlResolver::resolve('https://forum.example', '/index.php'),
        );
    }

    public function testSubfolderDeploymentRepairsMissingConfiguredBasePath(): void
    {
        self::assertSame(
            'https://forum.example/public',
            RuntimeCanonicalUrlResolver::resolve('https://forum.example', '/public/index.php'),
        );
    }

    public function testExplicitConfiguredBasePathRemainsAuthoritative(): void
    {
        self::assertSame(
            'https://forum.example/community',
            RuntimeCanonicalUrlResolver::resolve(
                'https://forum.example/community',
                '/public/index.php',
            ),
        );
    }

    public function testInstallerScriptDirectoryBecomesDeploymentBasePath(): void
    {
        self::assertSame(
            '/nested/public',
            RuntimeCanonicalUrlResolver::scriptBasePath('/nested/public/install.php'),
        );
        self::assertSame('', RuntimeCanonicalUrlResolver::scriptBasePath('/install.php'));
    }

    public function testUnsafeScriptNamesDoNotAffectCanonicalUrl(): void
    {
        self::assertSame(
            'https://forum.example',
            RuntimeCanonicalUrlResolver::resolve(
                'https://forum.example',
                "/public/..\n/index.php",
            ),
        );
    }
}
