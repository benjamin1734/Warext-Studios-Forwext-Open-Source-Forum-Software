<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class CommerceAnalyticsWebSurfaceTest extends TestCase
{
    public function testCommerceDashboardIsWiredWithSharedBackendPermission(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root.'/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root.'/app/Web/Analytics/CommerceAnalyticsHandler.php');
        $service = (string) file_get_contents($root.'/core/Analytics/Commerce/CommerceAnalyticsService.php');

        self::assertStringContainsString("'analytics.commerce'", $factory);
        self::assertStringContainsString("'/admin/analytics/commerce'", $factory);
        self::assertStringContainsString("$this->access->require($actor, 'analytics.view_commerce')", $service);
        self::assertStringContainsString("['7', '30', '90']", $handler);
        self::assertStringContainsString("'private, no-store'", $handler);
        self::assertStringContainsString("'noindex,nofollow'", $handler);
    }

    public function testRepositoryKeepsMoneyCurrencyScopedAndAvoidsSensitiveReferralGiveawayFields(): void
    {
        $root = dirname(__DIR__, 4);
        $repository = (string) file_get_contents(
            $root.'/core/Analytics/Commerce/DatabaseCommerceAnalyticsRepository.php',
        );

        foreach ([
            'forwext_marketplace_listings',
            'forwext_marketplace_external_sale_clicks',
            'forwext_marketplace_orders',
            'forwext_marketplace_order_history',
            'forwext_payment_refunds',
            'forwext_referral_clicks',
            'forwext_referral_attributions',
            'forwext_referral_rewards',
            'forwext_giveaway_entries',
            'forwext_giveaway_draws',
            'forwext_ad_events',
        ] as $table) {
            self::assertStringContainsString($table, $repository);
        }

        self::assertStringContainsString('GROUP BY o.currency', $repository);
        self::assertStringContainsString('GROUP BY a.currency', $repository);
        self::assertStringContainsString("'marketplace.listing.view'", $repository);
        self::assertStringContainsString("to_payment_state='paid'", $repository);

        foreach ([
            'ip_fingerprint',
            'device_fingerprint',
            'network_fingerprint',
            'billing_json',
            'receipt_json',
            'target_url',
            'viewer_user_id',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $repository);
        }
    }

    public function testListingViewProducerUsesStructuralListingReference(): void
    {
        $root = dirname(__DIR__, 4);
        $middleware = (string) file_get_contents($root.'/app/Web/Analytics/AnalyticsRequestMiddleware.php');
        $registry = (string) file_get_contents($root.'/core/Analytics/AnalyticsEventRegistry.php');

        self::assertStringContainsString("'marketplace.listing.view'", $middleware);
        self::assertStringContainsString("contentType:'marketplace_listing'", $middleware);
        self::assertStringContainsString('contentId:$listingId', $middleware);
        self::assertStringContainsString("'marketplace.listing.view'", $registry);
    }
}
