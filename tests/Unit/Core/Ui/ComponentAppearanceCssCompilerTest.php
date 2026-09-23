<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Appearance\ComponentAppearanceCssCompiler;
use Forwext\Core\Ui\Appearance\ComponentAppearanceRegistry;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use PHPUnit\Framework\TestCase;

final class ComponentAppearanceCssCompilerTest extends TestCase
{
    public function testCompilerEmitsTokenOnlyComponentVariables(): void
    {
        $tokens = DesignTokenCatalog::coreDefaults();
        $registry = ComponentAppearanceRegistry::coreDefaults($tokens);
        $css = (new ComponentAppearanceCssCompiler())->compile($registry, $tokens);

        self::assertStringStartsWith(':root{', $css);
        self::assertStringContainsString(
            '--forwext-component-header-background:var(--forwext-semantic-header-background)',
            $css,
        );
        self::assertStringContainsString(
            '--forwext-component-button-accent:var(--forwext-semantic-accent-primary)',
            $css,
        );
        self::assertStringContainsString(
            '--forwext-component-role-banner-background:var(--forwext-semantic-role-banner-background)',
            $css,
        );
        self::assertStringNotContainsString('url(', $css);
        self::assertStringNotContainsString('@import', $css);
    }
}
