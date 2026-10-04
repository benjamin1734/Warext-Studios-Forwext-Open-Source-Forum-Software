<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ForumReadStateWebSurfaceTest extends TestCase
{
    public function testForumListUsesPersistedUnreadStateWithoutPerThreadQueries(): void
    {
        $root = dirname(__DIR__, 4);
        $forum = (string) file_get_contents($root . '/app/Web/Forum/ForumViewHandler.php');
        $repository = (string) file_get_contents($root . '/core/Forum/State/DatabaseDiscussionStateRepository.php');
        $css = (string) file_get_contents($root . '/public/assets/site-components.css')
            . (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('unreadThreadIds($actor, $threadIds)', $forum);
        self::assertStringContainsString('isset($unread[$thread[\'thread_id\']])', $forum);
        self::assertStringContainsString('thread-badge thread-badge--unread">Okunmamış</span>', $forum);
        self::assertStringContainsString('($unread ? \' is-unread\' : \'\')', $forum);

        self::assertStringContainsString('public function unreadThreadIds(', $repository);
        self::assertStringContainsString('forwext_thread_read_state', $repository);
        self::assertStringContainsString('forwext_forum_read_state', $repository);
        self::assertStringContainsString('COALESCE(activity.latest_position, 0)', $repository);

        self::assertStringContainsString('.forum-thread-row.is-unread', $css);
        self::assertStringContainsString('.thread-badge--unread', $css);
    }

    public function testThreadViewMarksOnlyTheHighestRenderedVisiblePositionRead(): void
    {
        $root = dirname(__DIR__, 4);
        $thread = (string) file_get_contents($root . '/app/Web/Forum/ThreadViewHandler.php');
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString("max(array_column(\$posts['rows'], 'position'))", $thread);
        self::assertStringContainsString('$this->discussionState->markThreadRead(', $thread);
        self::assertStringContainsString('DiscussionStateException|InvalidArgumentException', $thread);

        self::assertStringContainsString('new DatabaseDiscussionStateRepository($database)', $factory);
        self::assertStringContainsString(
            "\$forumPublicReader,\n            \$discussionState,\n            \$viewerResolver",
            $factory,
        );
        self::assertStringContainsString(
            "\$forumPublicReader,\n            \$discussionState,\n            \$attachmentRepository,\n            \$editorPreview",
            $factory,
        );
    }
    public function testWatchAndForumMarkReadActionsUsePersistedStateAndCsrf(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $forum = (string) file_get_contents($root . '/app/Web/Forum/ForumViewHandler.php');
        $thread = (string) file_get_contents($root . '/app/Web/Forum/ThreadViewHandler.php');
        $watch = (string) file_get_contents($root . '/app/Web/Forum/DiscussionWatchHandler.php');
        $markRead = (string) file_get_contents($root . '/app/Web/Forum/ForumMarkReadHandler.php');

        self::assertStringContainsString("new PathTemplate('/forums/{slug}/watch'", $factory);
        self::assertStringContainsString("new PathTemplate('/forums/{slug}/mark-read'", $factory);
        self::assertStringContainsString("new PathTemplate('/threads/{threadId}/watch'", $factory);
        self::assertStringContainsString('[$forumCsrf]', $factory);

        self::assertStringContainsString('DiscussionWatchHtml::form(', $forum);
        self::assertStringContainsString('DiscussionWatchHtml::markForumReadForm(', $forum);
        self::assertStringContainsString('DiscussionWatchHtml::form(', $thread);
        self::assertStringContainsString('CsrfMiddleware::ATTRIBUTE_TOKEN', $forum . $thread);

        self::assertStringContainsString('new DiscussionStateService(', $watch . $markRead);
        self::assertStringContainsString('->watchThread(', $watch);
        self::assertStringContainsString('->watchForum(', $watch);
        self::assertStringContainsString('->markForumRead(', $markRead);
        self::assertStringContainsString('WatchNotificationMode::tryFrom', $watch);
    }


}
