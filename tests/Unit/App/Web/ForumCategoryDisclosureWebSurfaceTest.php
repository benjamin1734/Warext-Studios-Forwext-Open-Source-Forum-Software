<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ForumCategoryDisclosureWebSurfaceTest extends TestCase
{
    public function testForumIndexRendersNativePermissionScopedDisclosureWithoutClientSideDataDuplication(): void
    {
        $root = dirname(__DIR__, 4);
        $index = (string) file_get_contents($root . '/app/Web/Forum/ForumIndexHandler.php');
        $script = (string) file_get_contents($root . '/public/assets/forum-categories.js');
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('private function groups(', $index);
        self::assertStringContainsString("'key' => \$key", $index);
        self::assertStringContainsString("renderGroup(\$group['key']", $index);
        self::assertStringContainsString('<details class="forum-category" data-forum-category-key="', $index);
        self::assertStringContainsString('<summary class="forum-category-head">', $index);
        self::assertStringContainsString('forum-category-chevron', $index);
        self::assertStringContainsString("return \$html . '</div></details>'", $index);
        self::assertStringContainsString('/assets/forum-categories.js', $index);
        self::assertStringContainsString('details.forum-category[data-forum-category-key]', $script);
        self::assertStringContainsString('localStorage.getItem', $script);
        self::assertStringContainsString('localStorage.setItem', $script);
        self::assertStringContainsString('category.addEventListener("toggle"', $script);
        self::assertStringContainsString('details.forum-category:not([open])>.forum-node-list', $css);
        self::assertStringContainsString('details.forum-category>.forum-category-head:focus-visible', $css);
        self::assertStringContainsString('@media(prefers-reduced-motion:reduce)', $css);
    }

    public function testInstalledBrowserEvidenceCoversKeyboardPersistentAndCollapsedStates(): void
    {
        $root = dirname(__DIR__, 4);
        $fixture = (string) file_get_contents($root . '/tools/browser/render-shell-fixtures.php');
        $smoke = (string) file_get_contents($root . '/tools/browser/shell-smoke.mjs');
        $capture = (string) file_get_contents($root . '/tools/browser/visual-review-capture.mjs');

        self::assertStringContainsString('ci-fixture-general', $fixture);
        self::assertStringContainsString('AdminAssetsHtml::headAssets', $fixture);
        self::assertStringContainsString('/assets/forum-categories.js', $fixture);
        self::assertStringContainsString('await page.keyboard.press("Enter")', $smoke);
        self::assertStringContainsString('forwext.forum.category.ci-fixture-general', $smoke);
        self::assertStringContainsString('await page.reload', $smoke);
        self::assertStringContainsString('forum-index-category-collapsed', $capture);
        self::assertStringContainsString('manuallySignedOff: false', $capture);
        self::assertStringContainsString('stateRecords,', $capture);
    }
}
