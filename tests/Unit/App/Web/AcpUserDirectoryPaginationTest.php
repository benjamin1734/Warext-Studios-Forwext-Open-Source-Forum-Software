<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class AcpUserDirectoryPaginationTest extends TestCase
{
    public function testDirectoryUsesScopedCountAndStableBoundedPagination(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');

        self::assertStringContainsString('SELECT COUNT(*) FROM forwext_users', $service);
        self::assertStringContainsString('LIMIT \' . $perPage . \' OFFSET \' . $offset', $service);
        self::assertStringContainsString('ORDER BY updated_at_utc DESC,user_id DESC', $service);
        self::assertStringContainsString("'page'=>\$page", $service);
        self::assertStringContainsString("'page_count'=>\$pageCount", $service);
        self::assertStringContainsString("'total'=>\$total", $service);
        self::assertStringNotContainsString('ORDER BY updated_at_utc DESC,user_id DESC LIMIT 100', $service);
    }

    public function testPageInputIsValidatedAndPaginationEscapesItsLinks(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        self::assertStringContainsString('self::queryPage($query)', $handler);
        self::assertStringContainsString('ACP user page is invalid.', $handler);
        self::assertStringContainsString('ACP user page is out of range.', $handler);
        self::assertStringContainsString('aria-label="Kullanıcı dizini sayfaları"', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('rawurlencode($query)', $html);
        self::assertStringContainsString('ac-user-page[aria-current="page"]', $css);
    }

    public function testBrowserUsesSeededMultiPageUsersAndVisualGateChecksContent(): void
    {
        $root = dirname(__DIR__, 4);
        $seed = (string) file_get_contents($root . '/tools/browser/seed-phase12-fixtures.php');
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');
        $visual = (string) file_get_contents($root . '/tools/browser/visual-review-capture.mjs');

        self::assertStringContainsString('$index <= 121', $seed);
        self::assertStringContainsString('/admin/users?q=phase25&page=2', $live);
        self::assertStringContainsString('/admin/users?q=phase25&page=3', $live);
        self::assertStringContainsString('page=100001', $live);
        self::assertStringContainsString('ACP content starts below viewport', $visual);
        self::assertStringContainsString('ACP VISUAL ROUTE', $visual);
    }
}
