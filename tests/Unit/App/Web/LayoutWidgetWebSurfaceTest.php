<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class LayoutWidgetWebSurfaceTest extends TestCase
{
    public function testNativeShellRendersNamedRegionsAndWidgetSlots(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString('WidgetRegistry::withCoreDefaults()', $html);
        self::assertStringContainsString('new WidgetRenderService(', $html);
        foreach ([
            'page.before',
            'header.before',
            'header.after',
            'main.before',
            'main.after',
            'sidebar.primary',
            'footer.before',
            'footer.after',
            'page.after',
        ] as $slot) {
            self::assertStringContainsString("renderSlot('" . $slot . "'", $html);
        }

        self::assertStringContainsString('class="layout-sidebar"', $html);
        self::assertStringContainsString('data-forwext-responsive-target="sidebar"', $html);
        self::assertStringContainsString('class="site-footer"', $html);
        self::assertStringContainsString('Forwext · Warext Studios', (string) file_get_contents(
            $root . '/core/Ui/Widget/CoreBrandFooterWidget.php',
        ));
    }

    public function testWidgetLayerDoesNotReplaceBackendAuthorization(): void
    {
        $root = dirname(__DIR__, 4);
        $architecture = (string) file_get_contents(
            $root . '/docs/architecture/layout-regions-slots-widgets.md',
        );

        self::assertStringContainsString('never an authorization boundary', $architecture);
        self::assertStringContainsString('backend permission', $architecture);
    }
}
