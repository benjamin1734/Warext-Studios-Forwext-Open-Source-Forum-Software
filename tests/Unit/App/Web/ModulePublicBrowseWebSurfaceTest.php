<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ModulePublicBrowseWebSurfaceTest extends TestCase
{
    public function testMarketplacePublicBrowseUsesSharedSurfaceWithoutSearchHitCards(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Marketplace/MarketplaceHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString('marketplace-page discovery-page', $html);
        self::assertStringContainsString('surface-head marketplace-head', $html);
        self::assertStringContainsString('surface-panel marketplace-filter', $html);
        self::assertStringContainsString('surface-panel marketplace-results', $html);
        self::assertStringContainsString('market-card-category', $html);
        self::assertStringContainsString('surface-pagination market-pagination', $html);
        self::assertStringContainsString('.marketplace-results .market-card', $css);
        self::assertStringContainsString('market-detail-page discovery-page', $html);
        self::assertStringContainsString('surface-head market-detail-head', $html);
        self::assertStringContainsString('surface-panel market-detail-summary', $html);
        self::assertStringContainsString('surface-panel market-detail-reviews', $html);
        self::assertStringContainsString('market-seller-page discovery-page', $html);
    }

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
        self::assertStringContainsString('portfolio-project discovery-page', $html);
        self::assertStringContainsString('surface-head portfolio-project-head', $html);
        self::assertStringContainsString('surface-panel portfolio-project-content', $html);
        self::assertStringContainsString('class="portfolio-comment"', $html);
    }
    public function testGiveawayPublicSurfacesUseModuleSpecificCards(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Giveaway/GiveawayHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString('giveaway-index discovery-page', $html);
        self::assertStringContainsString('surface-head giveaway-head', $html);
        self::assertStringContainsString('class="giveaway-card"', $html);
        self::assertStringContainsString('giveaway-detail discovery-page', $html);
        self::assertStringContainsString('surface-panel giveaway-eligibility', $html);
        self::assertStringContainsString('if ($manage)', $html);
        self::assertStringContainsString('.giveaway-grid', $css);
    }

    public function testPortfolioManagementUsesSharedModuleSurface(): void
    {
        $root=dirname(__DIR__,4);
        $html=(string)file_get_contents($root.'/app/Web/Portfolio/PortfolioHtml.php');
        $css=(string)file_get_contents($root.'/public/assets/site-shell.css');

        self::assertStringContainsString('module-manage-page discovery-page',$html);
        self::assertStringContainsString('surface-head module-manage-head',$html);
        self::assertStringContainsString('surface-panel module-manage-panel',$html);
        self::assertStringContainsString('surface-panel module-manage-section',$html);
        self::assertStringContainsString('module-manage-details',$html);
        self::assertStringContainsString('.module-manage-panel',$css);
    }

    public function testGiveawayManagementAndProofUseSharedModuleSurfaces(): void
    {
        $root=dirname(__DIR__,4);
        $html=(string)file_get_contents($root.'/app/Web/Giveaway/GiveawayHtml.php');
        $css=(string)file_get_contents($root.'/public/assets/site-shell.css');

        self::assertStringContainsString('module-manage-page discovery-page',$html);
        self::assertStringContainsString('class="module-manage-row"',$html);
        self::assertStringContainsString('giveaway-proof-page discovery-page',$html);
        self::assertStringContainsString('surface-panel giveaway-proof-record',$html);
        self::assertStringContainsString('.module-manage-list',$css);
    }

}
