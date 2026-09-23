<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use Forwext\Core\Ui\DesignToken\DesignTokenCssCompiler;
use PHPUnit\Framework\TestCase;

final class DesignTokenCssCompilerTest extends TestCase
{
    public function testCompilerProducesDeterministicRootVariablesAndSemanticReferences(): void
    {
        $css = (new DesignTokenCssCompiler())->compile(DesignTokenCatalog::coreDefaults());

        self::assertStringStartsWith(':root{', $css);
        self::assertStringContainsString('--forwext-color-neutral-950:#0d1117', $css);
        self::assertStringContainsString(
            '--forwext-semantic-page-background:var(--forwext-color-neutral-950)',
            $css,
        );
        self::assertStringContainsString(
            '@media (prefers-reduced-motion: reduce){:root{',
            $css,
        );
        self::assertStringContainsString('--forwext-motion-duration-normal:0ms', $css);
    }
}
