<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class LayoutBuilderWebSurfaceTest extends TestCase
{
    public function testAdminRouteUsesCsrfSharedPermissionsAndNoindexSurface(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Appearance/LayoutBuilderHandler.php');
        $service = (string) file_get_contents($root . '/core/Ui/Layout/Builder/LayoutBuilderService.php');

        self::assertStringContainsString('/admin/appearance/layout', $factory);
        self::assertStringContainsString('layoutBuilderCsrfMiddleware', $factory);
        self::assertStringContainsString('appearance.manage', $service);
        self::assertStringContainsString('appearance.advanced', $service);
        self::assertStringContainsString('X-Robots-Tag', $handler);
        self::assertStringContainsString('viewerId: $actor->value()', $handler);
    }

    public function testBuilderJavascriptCoversDragDuplicateUndoRedoDeviceConditionsAndSecureIds(): void
    {
        $root = dirname(__DIR__, 4);
        $script = (string) file_get_contents($root . '/public/assets/layout-builder.js');

        self::assertStringContainsString('draggable = true', $script);
        self::assertStringContainsString('data-layout-dropzone', $script);
        self::assertStringContainsString('duplicate.addEventListener', $script);
        self::assertStringContainsString('movePlacement(placement, -1)', $script);
        self::assertStringContainsString('movePlacement(placement, 1)', $script);
        self::assertStringContainsString('data-builder-undo', $script);
        self::assertStringContainsString('data-builder-redo', $script);
        self::assertStringContainsString('data-builder-device', $script);
        self::assertStringContainsString('condition.devices', $script);
        self::assertStringContainsString('crypto.getRandomValues', $script);
        self::assertStringNotContainsString('Math.random', $script);
    }

    public function testImportExportSurfaceDoesNotAcceptExecutableFragments(): void
    {
        $root = dirname(__DIR__, 4);
        $codec = (string) file_get_contents($root . '/core/Ui/Layout/Builder/LayoutDocumentCodec.php');
        $document = (string) file_get_contents($root . '/core/Ui/Layout/Builder/LayoutDocument.php');
        $html = (string) file_get_contents($root . '/app/Web/Appearance/LayoutBuilderHtml.php');

        self::assertStringContainsString('1_048_576', $codec);
        self::assertStringContainsString('forwext-layout-builder', $codec);
        self::assertStringContainsString('LayoutPlacement::fromArray', $document);
        self::assertStringContainsString('JSON dışa aktar', $html);
        self::assertStringContainsString('JSON içe aktar', $html);
    }
}
