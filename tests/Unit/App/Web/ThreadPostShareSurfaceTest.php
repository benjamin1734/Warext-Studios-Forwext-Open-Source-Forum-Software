<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ThreadPostShareSurfaceTest extends TestCase
{
    public function testPostLinksArePageAwareAndShareControlsAreAccessible(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Forum/ThreadViewHandler.php');
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString("(\$page > 1 ? '?page=' . \$page : '')", $handler);
        self::assertStringContainsString("rawurlencode(\$post['post_id'])", $handler);
        self::assertStringContainsString("renderPost(\n", $handler);
        self::assertStringContainsString("                    \$posts['page'],", $handler);
        self::assertStringContainsString('class="thread-post-permalink" href="', $handler);
        self::assertStringContainsString('data-thread-copy-url="', $handler);
        self::assertStringContainsString('aria-live="polite"', $handler);
        self::assertStringContainsString('/assets/thread-post-share.js', $handler);
        self::assertStringContainsString('.thread-post:target', $css);
        self::assertStringContainsString('.thread-post-copy:focus-visible', $css);
    }

    public function testShareScriptStaysSameOriginAndPreservesFallbackFocus(): void
    {
        $root = dirname(__DIR__, 4);
        $script = (string) file_get_contents($root . '/public/assets/thread-post-share.js');
        $capture = (string) file_get_contents($root . '/tools/browser/visual-review-capture.mjs');

        self::assertStringContainsString('navigator.clipboard.writeText(value)', $script);
        self::assertStringContainsString('document.execCommand("copy")', $script);
        self::assertStringContainsString('previousFocus.focus({ preventScroll: true })', $script);
        self::assertStringContainsString('url.origin !== window.location.origin', $script);
        self::assertStringNotContainsString('fetch(', $script);
        self::assertStringContainsString('Post share copied a wrong or noncanonical permalink', $capture);
        self::assertStringContainsString('threadRoute.searchParams.get("page")', $capture);
    }
}
