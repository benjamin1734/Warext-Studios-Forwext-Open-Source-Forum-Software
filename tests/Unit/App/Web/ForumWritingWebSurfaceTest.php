<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ForumWritingWebSurfaceTest extends TestCase
{
    public function testPublicThreadAndReplyRoutesAreWiredWithCsrf(): void
    {
        $root = dirname(__DIR__,4);
        $factory = (string) file_get_contents($root.'/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString("'thread.create'", $factory);
        self::assertStringContainsString("'/forums/{slug}/new-thread'", $factory);
        self::assertStringContainsString("'thread.reply'", $factory);
        self::assertStringContainsString("'/threads/{threadId}/reply'", $factory);
        self::assertStringContainsString('$forumCsrf', $factory);
        self::assertStringContainsString("'forum-write'", $factory);
        self::assertStringContainsString('new ThreadCreateHandler(', $factory);
        self::assertStringContainsString('new ThreadReplyHandler(', $factory);
    }

    public function testThreadComposerUsesDomainServicesPermissionsAndContentPipeline(): void
    {
        $root = dirname(__DIR__,4);
        $handler = (string) file_get_contents($root.'/app/Web/Forum/ThreadCreateHandler.php');

        self::assertStringContainsString('ThreadPermission::Create->key()', $handler);
        self::assertStringContainsString('new ThreadPublishingService(', $handler);
        self::assertStringContainsString('ThreadTypeKey::fromString(\'discussion\')', $handler);
        self::assertStringContainsString('ThreadTitle::fromString($title)', $handler);
        self::assertStringContainsString('PostBody::fromString($message)', $handler);
        self::assertStringContainsString('pipeline: $this->pipeline', $handler);
        self::assertStringContainsString('name="_csrf"', $handler);
        self::assertStringContainsString('RichEditorView::render(', $handler);
        self::assertStringContainsString('EditorSurface::Thread', $handler);
        self::assertStringContainsString("headAssets: RichEditorView::assets", $handler);
        self::assertStringNotContainsString('<textarea name="body"', $handler);
    }

    public function testReplyComposerUsesDomainServicePermissionAndModerationAwareRedirect(): void
    {
        $root = dirname(__DIR__,4);
        $handler = (string) file_get_contents($root.'/app/Web/Forum/ThreadReplyHandler.php');

        self::assertStringContainsString('PostPermission::Create->key()', $handler);
        self::assertStringContainsString('->reply(', $handler);
        self::assertStringContainsString('pipeline: $this->pipeline', $handler);
        self::assertStringContainsString('PostModerationState::Visible', $handler);
        self::assertStringContainsString('?reply_pending=1', $handler);
        self::assertStringContainsString('name="_csrf"', $handler);
        self::assertStringContainsString('RichEditorView::render(', $handler);
        self::assertStringContainsString('EditorSurface::Post', $handler);
        self::assertStringContainsString("headAssets: RichEditorView::assets", $handler);
        self::assertStringNotContainsString('<textarea name="body"', $handler);
    }

    public function testBrowseSurfacesExposeWriteActionsOnlyThroughPermissionChecks(): void
    {
        $root = dirname(__DIR__,4);
        $forum = (string) file_get_contents($root.'/app/Web/Forum/ForumViewHandler.php');
        $thread = (string) file_get_contents($root.'/app/Web/Forum/ThreadViewHandler.php');
        $html = (string) file_get_contents($root.'/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString('canCreateThread($actor, $node)', $forum);
        self::assertStringContainsString('ThreadPermission::Create->key()', $forum);
        self::assertStringContainsString('use Forwext\\Core\\Forum\\Thread\\ThreadPermission;', $forum);
        self::assertStringContainsString('>Yeni konu</a>', $forum);
        self::assertStringContainsString('canReply($actor, $thread, $forum)', $thread);
        self::assertStringContainsString('PostPermission::Create->key()', $thread);
        self::assertStringContainsString('use Forwext\\Core\\Forum\\Post\\PostPermission;', $thread);
        self::assertStringContainsString('>Yanıtla</a>', $thread);
        self::assertStringContainsString('.forum-compose-form', $html);
        self::assertStringContainsString('.thread-view-actions', $html);
    }
}
