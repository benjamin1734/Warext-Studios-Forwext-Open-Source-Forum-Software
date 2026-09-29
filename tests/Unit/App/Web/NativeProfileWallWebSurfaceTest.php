<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class NativeProfileWallWebSurfaceTest extends TestCase
{
    public function testFactorySharesProfileActivityServiceWithWallAndApiHandlers(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        self::assertSame(1, substr_count($factory, 'new ProfileActivityService('));
        self::assertStringContainsString('new ProfileActivityWallRenderer(', $factory);
        self::assertStringContainsString('$profileActivityWall,', $factory);
        self::assertStringContainsString(
            "'profile.reactions', [HttpMethod::Get, HttpMethod::Post, HttpMethod::Put, HttpMethod::Delete]",
            $factory,
        );
    }

    public function testProfileViewAddsWallOnlyThroughAuthenticatedViewerContext(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Profile/ProfileViewHandler.php');

        self::assertStringContainsString('?ProfileActivityWallRenderer $activityWall = null', $handler);
        self::assertStringContainsString('$viewerId !== null && $this->activityWall !== null', $handler);
        self::assertStringContainsString('$this->activityWall->render($viewerId, $profile->userId)', $handler);
        self::assertStringContainsString('Profil Akışı', $handler);
        self::assertStringContainsString("$music . $tabNav . '<div class=\"profile-content\">' . $activityWall . $sections", $handler);
    }

    public function testRendererBoundsInitialQueriesAndEscapesUserContent(): void
    {
        $root = dirname(__DIR__, 4);
        $renderer = (string) file_get_contents($root . '/app/Web/Profile/ProfileActivityWallRenderer.php');

        self::assertStringContainsString('$this->activity->posts($viewerId, $profileOwnerId, 8, 0)', $renderer);
        self::assertStringContainsString('$this->activity->comments($viewerId, $post->id, 3, 0)', $renderer);
        self::assertStringContainsString('maxlength="10000"', $renderer);
        self::assertStringContainsString('ProfileHtml::escape($post->body->source())', $renderer);
        self::assertStringContainsString('ProfileHtml::escape($comment->body->source())', $renderer);
        self::assertStringContainsString('$this->activity->canPost($viewerId, $profileOwnerId)', $renderer);
        self::assertStringContainsString('Kendi profil gönderine tepki veremezsin.', $renderer);
    }

    public function testBrowserClientUsesProfileActivityCsrfAndLazyReactionLoading(): void
    {
        $root = dirname(__DIR__, 4);
        $asset = (string) file_get_contents($root . '/public/assets/profile-activity-wall.js');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-base.css')
            . (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString('/account/profile-activity/csrf', $asset);
        self::assertStringContainsString("'X-CSRF-Token': token", $asset);
        self::assertStringContainsString("method: 'POST'", $asset);
        self::assertStringContainsString('/profile-posts/', $asset);
        self::assertStringContainsString('/profile-posts', $asset);
        self::assertStringContainsString("reactionMenu.addEventListener('toggle'", $asset);
        self::assertStringContainsString('if (!reactionMenu.open || summaryLoaded) return;', $asset);
        self::assertStringContainsString('textContent =', $asset);
        self::assertStringContainsString('replaceChildren', $asset);
        self::assertStringNotContainsString('innerHTML', $asset);
        self::assertStringContainsString('/assets/profile-activity-wall.js', $profile);
        self::assertStringContainsString('.profile-wall-reaction-popover', $css);
    }

    public function testProfileReactionHandlerKeepsLegacyMethodsAndAddsPostAction(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Profile/ProfilePostReactionHandler.php');

        self::assertStringContainsString('HttpMethod::Get =>', $handler);
        self::assertStringContainsString('HttpMethod::Put =>', $handler);
        self::assertStringContainsString('HttpMethod::Delete =>', $handler);
        self::assertStringContainsString('HttpMethod::Post =>', $handler);
        self::assertStringContainsString("'react' =>", $handler);
        self::assertStringContainsString("'remove_reaction' =>", $handler);
        self::assertStringContainsString('$request->isJson()', $handler);
        self::assertStringContainsString('$request->json()', $handler);
    }
}
