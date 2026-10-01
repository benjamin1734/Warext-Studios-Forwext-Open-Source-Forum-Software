<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ForumLayoutWebSurfaceTest extends TestCase
{
    public function testForumIndexUsesStructuredNodeRowsAndForumIconography(): void
    {
        $root = dirname(__DIR__, 4);
        $index = (string) file_get_contents($root . '/app/Web/Forum/ForumIndexHandler.php');

        self::assertStringContainsString('class="forum-category"', $index);
        self::assertStringContainsString('class="forum-node"', $index);
        self::assertStringContainsString('class="forum-node-icon"', $index);
        self::assertStringContainsString('<svg viewBox="0 0 24 24"', $index);
        self::assertStringContainsString('class="forum-node-counts"', $index);
        self::assertStringContainsString('class="forum-node-last"', $index);
        self::assertStringContainsString('class="forum-last-activity"', $index);
        self::assertStringContainsString('class="forum-last-avatar"', $index);
        self::assertStringContainsString('class="forum-recent-item"', $index);
        self::assertStringContainsString('class="forum-recent-avatar"', $index);
        self::assertStringContainsString('class="forum-recent-user"', $index);

        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');
        self::assertStringContainsString('/* forum-empty-state-v2 */', $css);
        self::assertStringContainsString('.forum-empty-state{', $css);
    }

    public function testForumThreadListUsesExplicitDesktopColumns(): void
    {
        $root = dirname(__DIR__, 4);
        $forum = (string) file_get_contents($root . '/app/Web/Forum/ForumViewHandler.php');

        self::assertStringContainsString('class="forum-thread-list-head"', $forum);
        self::assertStringContainsString('<span>Konu</span>', $forum);
        self::assertStringContainsString('<span>Yanıt</span>', $forum);
        self::assertStringContainsString('<span>Son mesaj</span>', $forum);
        self::assertStringContainsString('class="thread-status-icon"', $forum);
        self::assertStringContainsString('<svg viewBox="0 0 24 24"', $forum);
        self::assertStringContainsString('class="forum-last-avatar"', $forum);
        self::assertStringContainsString('class="forum-thread-author"', $forum);
        self::assertStringContainsString('forum-thread-pagination--top', $forum);
        self::assertStringContainsString('forum-thread-pagination--bottom', $forum);
        self::assertStringContainsString("is-sticky", $forum);
        self::assertStringContainsString('?forum=', $forum);
    }

    public function testThreadViewKeepsDedicatedTitleAndClassicAuthorColumnHooks(): void
    {
        $root = dirname(__DIR__, 4);
        $thread = (string) file_get_contents($root . '/app/Web/Forum/ThreadViewHandler.php');
        $css = (string) file_get_contents($root . '/public/assets/site-components.css')
            . (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('class="thread-view-title"', $thread);
        self::assertStringContainsString('class="thread-post-author"', $thread);
        self::assertStringContainsString('class="thread-post-body"', $thread);
        self::assertStringContainsString('.thread-post-author{', $css);
        self::assertStringContainsString('.thread-post-meta{', $css);
        self::assertStringContainsString('.thread-post-author-copy', $css);
        self::assertStringContainsString('.thread-quick-reply{', $css);
        self::assertStringContainsString('thread-pagination--top', $thread);
        self::assertStringContainsString('thread-pagination--bottom', $thread);
    }
}
