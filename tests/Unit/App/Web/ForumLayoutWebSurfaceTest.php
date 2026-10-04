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
        self::assertStringContainsString('.forum-home-layout{', $css);
        self::assertStringContainsString('.forum-home-main{', $css);
        self::assertStringContainsString('.forum-home-side{', $css);
        self::assertGreaterThanOrEqual(3, substr_count($css, 'display:grid;'));
        self::assertStringContainsString('grid-template-columns:repeat(3,minmax(0,1fr));', $css);
        self::assertStringContainsString('forum-side-card--empty', $index);
        self::assertStringContainsString('class="forum-hero forum-page-head"', $index);
        self::assertStringContainsString('<h2>Gündem</h2>', $index);
        self::assertStringContainsString('forum-side-card--discovery', $index);
        self::assertStringContainsString("'/activity/threads/unread'", $index);
        self::assertStringContainsString("'/activity/threads/trending'", $index);
        self::assertStringContainsString("'/activity/threads/featured'", $index);
        self::assertStringContainsString("'/activity/profile-posts'", $index);
        self::assertStringContainsString("' forum</span>'", $index);
        self::assertStringContainsString('forum-side-card--stats', $index);
        self::assertStringContainsString('forum-side-card--discovery', $index);
        self::assertStringContainsString("'/activity/threads/new'", $index);
        self::assertStringContainsString("'/activity/threads/unread'", $index);
        self::assertStringContainsString("'/activity/threads/trending'", $index);
        self::assertStringContainsString("'/activity/threads/featured'", $index);
        self::assertStringContainsString("'/activity/profile-posts'", $index);
        self::assertStringContainsString("number_format(count(\$forums), 0, ',', '.')", $index);
        self::assertStringContainsString(" forum</span>", $index);
        self::assertStringContainsString('/* reference-forum-r2 */', $css);
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
        self::assertStringContainsString('class="forum-view-head forum-page-head"', $forum);
        self::assertStringContainsString('class="forum-thread-filter-link"', $forum);
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
        self::assertStringContainsString('class="thread-view-head forum-page-head"', $thread);
        self::assertStringContainsString('<h2>Yanıt yaz</h2>', $thread);
        self::assertStringContainsString('class="thread-view-action-main"', $thread);
        self::assertStringContainsString('class="thread-view-action-follow"', $thread);
        self::assertStringContainsString('class="thread-post-footer"', $thread);
        self::assertStringContainsString('class="pagination thread-pagination-nav"', $thread);
        self::assertStringContainsString('class="pagination-edge"', $thread);
        self::assertStringContainsString('class="pagination-gap"', $thread);
        self::assertStringContainsString('rel="prev"', $thread);
        self::assertStringContainsString('rel="next"', $thread);
        self::assertStringContainsString('/* thread-view-density-v1 */', $css);
        self::assertStringContainsString('.thread-post-footer{', $css);
        self::assertStringContainsString('.thread-pagination-nav{', $css);
        self::assertStringContainsString('/* reference-forum-r2 */', $css);
    }
}
