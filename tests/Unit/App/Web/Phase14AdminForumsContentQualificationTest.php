<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class Phase14AdminForumsContentQualificationTest extends TestCase
{
    public function testForumAndContentAcpDensityIsCoveredByLiveBrowser(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        foreach ([
            '/admin/forums?node=',
            '/admin/content',
            'admin forums: dense node workspace contract failed',
            'admin forums mobile: responsive contract failed',
            'admin content: operations workspace contract failed',
            'admin content mobile: responsive contract failed',
        ] as $needle) {
            self::assertStringContainsString($needle, $live);
        }

        foreach ([
            'ac-forum-overview',
            'ac-forum-table',
            'ac-forum-facts',
            'ac-forum-editor-form',
            'ac-content-overview',
            'ac-content-operation',
        ] as $marker) {
            self::assertStringContainsString($marker, $html);
            self::assertStringContainsString($marker, $css);
        }

        self::assertStringContainsString('00000000000000000000000000000002', $live);
        self::assertStringContainsString('@media(max-width:700px)', $css);
        self::assertStringContainsString('@media(max-width:520px)', $css);
    }

    public function testForumHierarchyAndContentMutationOwnershipRemainFirstParty(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHandler.php');
        $repository = (string) file_get_contents($root . '/core/Forum/Node/DatabaseForumNodeRepository.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHtml.php');

        self::assertStringContainsString('$this->nodes->all()', $service);
        self::assertStringContainsString('$this->nodes->find($selectedNodeId)', $service);
        self::assertStringContainsString('$this->nodes->save($node)', $service);
        self::assertStringContainsString('new ForumNodeHierarchy($nodes)', $repository);
        self::assertStringContainsString("action === 'save_node'", $handler);
        self::assertStringContainsString("['content_manager']", $html);
        self::assertStringContainsString("['moderation']", $html);
        self::assertStringContainsString('/content-manager', $html);
        self::assertStringContainsString('/moderation/approval', $html);
        self::assertStringContainsString('/moderation/freshness', $html);
        self::assertStringNotContainsString('UPDATE forwext_threads', $html);
        self::assertStringNotContainsString('UPDATE forwext_posts', $html);
    }
}
