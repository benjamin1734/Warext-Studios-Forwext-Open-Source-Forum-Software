<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ForumHomeReferenceSurfaceTest extends TestCase
{
    public function testHomeRendersPrivacyScopedOnlineMembersAndPermittedCreationPaths(): void
    {
        $root = dirname(__DIR__, 4);
        $home = (string) file_get_contents($root . '/app/Web/Forum/ForumIndexHandler.php');
        $wiring = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString('$this->presence->online($actor !== null, 8)', $home);
        self::assertStringContainsString('ThreadPermission::Create->key()', $home);
        self::assertStringContainsString('forumSettings()?->allowNewThreads()', $home);
        self::assertStringContainsString('renderCreateMenu($writableForums)', $home);
        self::assertStringContainsString('<details class="forum-create-menu">', $home);
        self::assertStringContainsString('/new-thread', $home);
        self::assertStringContainsString('renderOnline($onlineUsers)', $home);
        self::assertStringContainsString('ProfileHtml::memberPath($this->basePath, $online->username)', $home);
        self::assertStringContainsString('/members/staff', $home);
        self::assertStringContainsString('/members/online', $home);
        self::assertStringContainsString('forum-home-share.js', $home);
        self::assertStringContainsString('$authorizer,' . "\n" . '            $presence,' . "\n" . '            $basePath,', $wiring);
        self::assertStringNotContainsString('$this->presence->heartbeat(', $home);
    }

    public function testShareControlUsesFirstPartyClientBehaviorAndLiveBrowserAcceptance(): void
    {
        $root = dirname(__DIR__, 4);
        $js = (string) file_get_contents($root . '/public/assets/forum-home-share.js');
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');
        $browser = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');
        $html = (string) file_get_contents($root . '/app/Web/Forum/ForumIndexHandler.php');

        self::assertStringContainsString('data-forum-share-copy', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('window.location.href', $js);
        self::assertStringContainsString('navigator.clipboard.writeText(url)', $js);
        self::assertStringContainsString('document.execCommand("copy")', $js);
        self::assertStringNotContainsString('fetch(', $js);
        self::assertStringContainsString('.forum-side-online-list', $css);
        self::assertStringContainsString('.forum-create-options a:focus-visible', $css);
        self::assertStringContainsString('home: copy-link action copied incorrect URL', $browser);
        self::assertStringContainsString('home: reference sidebar or guest permissions mismatch', $browser);
    }

    public function testForumPaginationHasEdgeNavigationAndAccessibleCurrentPage(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Forum/ForumViewHandler.php');

        self::assertStringContainsString('class="pagination forum-directory-pagination"', $html);
        self::assertStringContainsString('rel="prev"', $html);
        self::assertStringContainsString('rel="next"', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('pagination-gap', $html);
    }
}
