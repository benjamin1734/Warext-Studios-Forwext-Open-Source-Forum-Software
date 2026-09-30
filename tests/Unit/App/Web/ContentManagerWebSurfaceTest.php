<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ContentManagerWebSurfaceTest extends TestCase
{
    public function testContentManagerUsesSharedModerationWorkspaceSurfaces(): void
    {
        $root=dirname(__DIR__,4);
        $handler=(string)file_get_contents($root.'/app/Web/ContentManager/ContentManagerHandler.php');
        $operation=(string)file_get_contents($root.'/app/Web/ContentManager/ContentManagerOperationHandler.php');
        $css=(string)file_get_contents($root.'/public/assets/site-shell.css');

        self::assertStringContainsString('content-manager-page discovery-page',$handler);
        self::assertStringContainsString('surface-head content-manager-head',$handler);
        self::assertStringContainsString('class="content-manager-row"',$handler);
        self::assertStringContainsString('class="content-manager-preview"',$handler);
        self::assertStringContainsString('content-operation-page discovery-page',$operation);
        self::assertStringContainsString('class="content-operation-target"',$operation);
        self::assertStringContainsString('.content-manager-list',$css);
        self::assertStringContainsString('.content-operation-bar',$css);
    }
}
