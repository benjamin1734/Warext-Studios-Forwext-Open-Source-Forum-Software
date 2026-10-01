<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class MarketplacePurchaseWebSurfaceTest extends TestCase
{
    public function testMarketplacePurchaseFlowUsesSharedCommerceSurfaces(): void
    {
        $root=dirname(__DIR__,4);
        $html=(string)file_get_contents($root.'/app/Web/Marketplace/MarketplacePurchaseHtml.php');
        $css=(string)file_get_contents($root.'/public/assets/site-components.css')
            .(string)file_get_contents($root.'/public/assets/site-pages.css');

        self::assertStringContainsString('market-commerce-page discovery-page',$html);
        self::assertStringContainsString('class="market-cart-row',$html);
        self::assertStringContainsString('market-checkout-summary',$html);
        self::assertStringContainsString('class="market-order-row"',$html);
        self::assertStringContainsString('market-order-summary',$html);
        self::assertStringContainsString('market-order-history-row',$html);
        self::assertStringContainsString('.market-commerce-panel',$css);
    }
}
