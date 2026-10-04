<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Search;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Search\ThreadDiscoveryHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Search\Discovery\DiscoveryMode;
use Forwext\Core\Search\Discovery\DiscoveryThread;
use PHPUnit\Framework\TestCase;

final class ThreadDiscoveryWebSurfaceTest extends TestCase
{
    public function testDiscoverySurfaceRendersCompactModeTabsAndEscapesThreadTitles(): void
    {
        $html = ThreadDiscoveryHtml::page(
            [
                new DiscoveryThread(
                    EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
                    EntityId::fromString('11111111111111111111111111111111'),
                    null,
                    '<script>alert(1)</script>',
                    new DateTimeImmutable('2026-10-04T08:00:00+00:00'),
                    new DateTimeImmutable('2026-10-04T10:00:00+00:00'),
                    true,
                    true,
                    8,
                    4,
                ),
            ],
            DiscoveryMode::Featured,
            1,
            false,
            new BasePath('/community'),
            new DateTimeZone('Europe/Istanbul'),
        );

        self::assertStringContainsString('thread-discovery-row is-unread', $html);
        self::assertStringContainsString('Öne çıkanlar', $html);
        self::assertStringContainsString('Okunmamış', $html);
        self::assertStringContainsString('href="/community/activity/threads/new"', $html);
        self::assertStringContainsString('href="/community/activity/threads/unread"', $html);
        self::assertStringContainsString('href="/community/activity/threads/trending"', $html);
        self::assertStringContainsString('aria-current="page" href="/community/activity/threads/featured"', $html);
        self::assertStringContainsString('href="/community/activity/threads/no-replies"', $html);
        self::assertStringContainsString('href="/community/activity/threads/mine"', $html);
        self::assertStringContainsString('href="/community/activity/threads/participated"', $html);
        self::assertStringContainsString('href="/community/activity/threads/recent"', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('<strong>7</strong><span>Yanıt</span>', $html);
        self::assertStringContainsString('<strong>4</strong><span>7 gün</span>', $html);
    }

    public function testDiscoveryRouteReusesPermissionAwareForumScopeBackend(): void
    {
        $root = dirname(__DIR__, 5);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Search/ThreadDiscoveryHandler.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString('new ThreadDiscoveryService(', $factory);
        self::assertStringContainsString('new DatabaseThreadDiscoveryRepository($database)', $factory);
        self::assertStringContainsString('[$forumScopeProvider]', $factory);
        self::assertStringContainsString("new PathTemplate('/activity/threads')", $factory);
        self::assertStringContainsString("new PathTemplate('/activity/threads/{mode}'", $factory);
        self::assertStringContainsString("'mode'=>'new|unread|trending|featured|no-replies|mine|participated|recent'", $factory);
        self::assertStringContainsString('$actor = $this->viewers->resolve($request);', $handler);
        self::assertStringContainsString('DiscoveryMode::Unread', $handler);
        self::assertStringContainsString('DiscoveryMode::Trending', $handler);
        self::assertStringContainsString('DiscoveryMode::Featured', $handler);
        self::assertStringContainsString('DiscoveryMode::NoReplies', $handler);
        self::assertStringContainsString('DiscoveryMode::StartedByViewer', $handler);
        self::assertStringContainsString('DiscoveryMode::ParticipatedByViewer', $handler);
        self::assertStringContainsString("'/activity/threads/new'", $profile);
        self::assertStringContainsString("'/activity/threads/unread'", $profile);
        self::assertStringContainsString("'/activity/threads/featured'", $profile);
        self::assertStringContainsString("'/activity/threads/no-replies'", $profile);
        self::assertStringContainsString("'/activity/threads/mine'", $profile);
        self::assertStringContainsString("'/activity/threads/participated'", $profile);

        $forumIndex = (string) file_get_contents($root . '/app/Web/Forum/ForumIndexHandler.php');
        self::assertStringContainsString("'/activity/threads/new'", $forumIndex);
        self::assertStringContainsString('Yeni konular', $forumIndex);

        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');
        self::assertStringContainsString('/activity/threads/featured', $live);
        self::assertStringContainsString('thread discovery: active/layout contract failed', $live);
    }
}
