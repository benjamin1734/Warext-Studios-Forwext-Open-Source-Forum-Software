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
}
