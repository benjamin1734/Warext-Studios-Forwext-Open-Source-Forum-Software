<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ThreadSocialControlsWebSurfaceTest extends TestCase
{
    public function testFactoryAllowsBrowserPostActionsWithoutRemovingExistingApiMethods(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $reaction = (string) file_get_contents($root . '/app/Web/Social/PostReactionHandler.php');
        $bookmark = (string) file_get_contents($root . '/app/Web/Social/PostBookmarkHandler.php');

        self::assertStringContainsString(
            "'post.reactions', [HttpMethod::Get, HttpMethod::Post, HttpMethod::Put, HttpMethod::Delete]",
            $factory,
        );
        self::assertStringContainsString(
            "'post.bookmark', [HttpMethod::Post, HttpMethod::Put, HttpMethod::Delete]",
            $factory,
        );
        self::assertStringContainsString("'react' =>", $reaction);
        self::assertStringContainsString("'remove_reaction' =>", $reaction);
        self::assertStringContainsString("'save_bookmark'", $bookmark);
        self::assertStringContainsString("'remove_bookmark'", $bookmark);
        self::assertStringContainsString('$request->isJson()', $reaction);
        self::assertStringContainsString('$request->json()', $reaction);
        self::assertStringContainsString('$request->isJson()', $bookmark);
        self::assertStringContainsString('$request->json()', $bookmark);
    }

    public function testThreadViewRendersAuthenticatedControlsAndProtectsOwnPostReaction(): void
    {
        $root = dirname(__DIR__, 4);
        $thread = (string) file_get_contents($root . '/app/Web/Forum/ThreadViewHandler.php');

        self::assertStringContainsString("\$this->renderPost(\$post, \$actor, \$canReply, \$attachmentsByPost[\$post['post_id']] ?? [])", $thread);
        self::assertStringContainsString('data-thread-interactions', $thread);
        self::assertStringContainsString("'like' => ['👍', 'Beğen']", $thread);
        self::assertStringContainsString("'angry' => ['😠', 'Kızgın']", $thread);
        self::assertStringContainsString('data-reaction-key="', $thread);
        self::assertStringContainsString('data-bookmark-form', $thread);
        self::assertStringContainsString('data-quote-post', $thread);
        self::assertStringContainsString('maxlength="1000"', $thread);
        self::assertStringContainsString('hash_equals($actor->value(), $post[\'author_user_id\'])', $thread);
        self::assertStringContainsString('Kendi mesajına tepki veremezsin.', $thread);
    }

    public function testBrowserClientUsesOneCsrfTokenContractAndSafeDomRendering(): void
    {
        $root = dirname(__DIR__, 4);
        $asset = (string) file_get_contents($root . '/public/assets/thread-interactions.js');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-base.css')
            . (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString('/account/interactions/csrf', $asset);
        self::assertStringContainsString("'X-CSRF-Token': token", $asset);
        self::assertStringContainsString("method: 'POST'", $asset);
        self::assertStringContainsString("action: 'react'", $asset);
        self::assertStringContainsString("action: 'save_bookmark'", $asset);
        self::assertStringContainsString('textContent =', $asset);
        self::assertStringContainsString('replaceChildren', $asset);
        self::assertStringContainsString('ForwextRichEditor', $asset);
        self::assertStringContainsString("quotePost(editorRoot, postId)", $asset);
        self::assertStringNotContainsString('innerHTML', $asset);
        self::assertStringContainsString('/assets/thread-interactions.js', $profile);
        self::assertStringContainsString('.thread-reaction-popover', $css);
        self::assertStringContainsString('.thread-bookmark-form', $css);
    }

    public function testClientLoadsReactionSummaryLazilyInsteadOfOnPageBootstrap(): void
    {
        $root = dirname(__DIR__, 4);
        $asset = (string) file_get_contents($root . '/public/assets/thread-interactions.js');

        self::assertStringContainsString("reactionMenu.addEventListener('toggle'", $asset);
        self::assertStringContainsString('if (!reactionMenu.open || summaryLoaded) return;', $asset);
        self::assertStringNotContainsString('Promise.all(roots', $asset);
    }
}
