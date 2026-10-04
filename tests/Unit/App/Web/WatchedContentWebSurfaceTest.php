<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Forum\WatchedContentHtml;
use Forwext\Core\Forum\State\WatchNotificationMode;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class WatchedContentWebSurfaceTest extends TestCase
{
    public function testWatchedSurfaceRendersCompactSafeAccountRows(): void
    {
        $html = WatchedContentHtml::page(
            [[
                'title' => '<script>watched</script>',
                'subtitle' => 'Forum açıklaması',
                'href' => '/community/threads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'mode' => WatchNotificationMode::InAppEmail,
                'updated_at' => new DateTimeImmutable('2026-10-04T10:00:00+00:00'),
            ]],
            true,
            new BasePath('/community'),
            new DateTimeZone('Europe/Istanbul'),
        );

        self::assertStringContainsString('watched-row', $html);
        self::assertStringContainsString('aria-current="page" href="/community/account/watched/threads"', $html);
        self::assertStringContainsString('href="/community/account/watched/forums"', $html);
        self::assertStringContainsString('Uygulama içi + e-posta', $html);
        self::assertStringContainsString('&lt;script&gt;watched&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>watched</script>', $html);
    }

    public function testWatchedRoutesFilterThroughForumPermissionsAndExistingStateTables(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Forum/WatchedContentHandler.php');
        $repository = (string) file_get_contents($root . '/core/Forum/State/DatabaseDiscussionStateRepository.php');
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');

        self::assertStringContainsString("new PathTemplate('/account/watched/threads')", $factory);
        self::assertStringContainsString("new PathTemplate('/account/watched/forums')", $factory);
        self::assertStringContainsString('new ForumNodeAuthorization(new PermissionGate(', $handler);
        self::assertStringContainsString('ThreadModerationState::Visible', $handler);
        self::assertStringContainsString('->canView($hierarchy, $forum->id())', $handler);

        self::assertStringContainsString('FROM `forwext_watched_threads`', $repository);
        self::assertStringContainsString('FROM `forwext_watched_forums`', $repository);
        self::assertStringContainsString("'/account/watched/threads'", $navigation);
        self::assertStringContainsString("'/account/watched/forums'", $navigation);
    }
}
