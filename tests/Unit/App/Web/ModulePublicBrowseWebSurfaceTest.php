<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ModulePublicBrowseWebSurfaceTest extends TestCase
{
    public function testPortfolioPublicIndexUsesModuleSpecificSharedSurface(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Portfolio/PortfolioHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString('portfolio-index discovery-page', $html);
        self::assertStringContainsString('surface-head portfolio-head', $html);
        self::assertStringContainsString('surface-panel portfolio-index-panel', $html);
        self::assertStringContainsString('class="portfolio-card"', $html);
        self::assertStringContainsString('class="portfolio-card-body"', $html);
        self::assertStringContainsString('.portfolio-grid', $css);
        self::assertStringContainsString('.portfolio-card-media', $css);
    }
}
