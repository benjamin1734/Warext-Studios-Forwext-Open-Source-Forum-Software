<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Faq;

use PHPUnit\Framework\TestCase;

final class FaqSupportDraftWebSurfaceTest extends TestCase
{
    public function testSupportDraftQueueUsesSharedManagementSurface(): void
    {
        $root=dirname(__DIR__,5);
        $html=(string)file_get_contents($root.'/app/Web/Faq/FaqSupportDraftHtml.php');
        $css=(string)file_get_contents($root.'/public/assets/site-shell.css');

        self::assertStringContainsString('module-manage-page discovery-page',$html);
        self::assertStringContainsString('surface-panel faq-draft-panel',$html);
        self::assertStringContainsString('class="faq-draft-row"',$html);
        self::assertStringContainsString('class="faq-draft-actions"',$html);
        self::assertStringContainsString('.support-faq-row',$css);
    }
}
