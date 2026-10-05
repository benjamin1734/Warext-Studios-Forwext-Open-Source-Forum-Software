<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class Phase14AdminForumsContentQualificationTest extends TestCase
{
    public function testInstalledRuntimeCoversForumSelectionContentOverviewAndMobile(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');
        $workflow = (string) file_get_contents($root . '/.github/workflows/qualification-matrix.yml');
        $fixture = (string) file_get_contents($root . '/tools/browser/seed-phase14-fixtures.php');

        self::assertStringContainsString('/admin/forums?q=phase14', $live);
        self::assertStringContainsString('14141414141414141414141414141414', $live);
        self::assertStringContainsString('/admin/content', $live);
        self::assertStringContainsString('admin forums: selected node contract failed', $live);
        self::assertStringContainsString('admin forums mobile: responsive contract failed', $live);
        self::assertStringContainsString('admin content mobile: responsive contract failed', $live);
        self::assertStringContainsString('seed-phase14-fixtures.php', $workflow);
        self::assertStringContainsString('DatabaseForumNodeRepository', $fixture);
        self::assertStringContainsString('Phase 14 Browser Forum', $fixture);
        self::assertStringContainsString('repository round-trip verification', $fixture);
    }

    public function testForumAndContentRenderersUseDenseRealBackendSurfaces(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');

        foreach ([
            'ac-forum-shell',
            'ac-forum-overview',
            'ac-node-table',
            'ac-node-summary',
            'ac-node-editor',
            'ac-node-options',
            'ac-content-shell',
            'ac-content-overview',
            'ac-content-actions',
            'ac-content-action',
        ] as $marker) {
            self::assertStringContainsString($marker, $html);
            self::assertStringContainsString($marker, $css);
        }

        self::assertStringContainsString('$this->nodes->all()', $service);
        self::assertStringContainsString('$this->nodes->find($selectedNodeId)', $service);
        self::assertStringContainsString('forwext_threads', $service);
        self::assertStringContainsString('forwext_posts', $service);
        self::assertStringContainsString('name="action" value="save_node"', $html);
        self::assertStringNotContainsString('style="margin-top:12px"', $html);
        self::assertStringContainsString('acp-forums-content-density-v2', $css);
        self::assertStringContainsString('.ac-node-table{display:block;min-width:0;width:100%}', $css);
        self::assertStringContainsString('content:"Parent"', $css);
        self::assertStringContainsString('content:"Mesaj"', $css);
    }
}
