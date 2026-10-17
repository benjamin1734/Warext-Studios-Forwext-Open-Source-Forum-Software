<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class PermissionScopedAdminSidebarNavigationTest extends TestCase
{
    public function testSidebarEndpointUsesAuthoritativeEffectivePermissionsAndPrivateJson(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $service = (string) file_get_contents($root . '/core/Admin/AdminInformationArchitectureService.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminSidebarNavigationHandler.php');

        self::assertStringContainsString("new PathTemplate('/admin/sidebar-navigation')", $factory);
        self::assertStringContainsString('new AdminSidebarNavigationHandler($adminInformation, $viewerResolver, $basePath)', $factory);
        self::assertStringContainsString('[HttpMethod::Get]', $factory);
        self::assertStringContainsString('function navigationSections(EntityId $actor)', $service);
        self::assertStringContainsString('$this->requireAccess($actor)', $service);
        self::assertStringContainsString('$this->navigation->visible($actor)', $service);
        self::assertStringContainsString('$this->viewers->resolve($request)', $handler);
        self::assertStringContainsString('$this->administration->navigationSections($actor)', $handler);
        self::assertStringContainsString('$this->basePath->prepend($item->path)', $handler);
        self::assertStringContainsString('PermissionDeniedException', $handler);
        self::assertStringContainsString('private, no-store', $handler);
        self::assertStringContainsString('noindex,nofollow', $handler);
        self::assertStringContainsString('nosniff', $handler);
    }

    public function testSidebarDoesNotPublishGuessedRoutesOrNavigationSearchPlaceholders(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $assets = (string) file_get_contents($root . '/app/Web/Admin/AdminAssetsHtml.php');
        $script = (string) file_get_contents($root . '/public/assets/admin-navigation.js');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        self::assertStringContainsString('data-acp-navigation-list', $html);
        self::assertStringContainsString('data-acp-navigation-url', $html);
        self::assertStringContainsString('Yönetim alanlarını görüntüle', $html);
        self::assertStringNotContainsString('$adminLookup', $html);
        self::assertStringContainsString('/assets/admin-navigation.js', $assets);
        self::assertStringContainsString('credentials: "same-origin"', $script);
        self::assertStringContainsString('cache: "no-store"', $script);
        self::assertStringContainsString('response.ok', $script);
        self::assertStringContainsString('textContent = item.label', $script);
        self::assertStringContainsString('aria-current', $script);
        self::assertStringContainsString('host.replaceChildren(...groups)', $script);
        self::assertStringContainsString('.acp-app-nav-group nav>a[aria-current="page"]', $css);
        self::assertStringNotContainsString('.acp-app-nav-group:not([open]){display:none}', $css);
    }
}
