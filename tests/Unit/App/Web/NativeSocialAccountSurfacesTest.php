<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ActivityFeedHtml;
use Forwext\App\Web\Profile\ProfileActivitySettingsHtml;
use Forwext\App\Web\Social\BookmarkListHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Profile\Activity\ActivityFeedEntry;
use Forwext\Core\Profile\Activity\ActivityFeedType;
use Forwext\Core\Profile\Activity\ProfileActivityScope;
use Forwext\Core\Profile\Activity\ProfileActivitySettings;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Social\Interaction\BookmarkEntry;
use PHPUnit\Framework\TestCase;

final class NativeSocialAccountSurfacesTest extends TestCase
{
    public function testFactoryKeepsJsonApisWhileEnablingBrowserSurfaces(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $bookmarks = (string) file_get_contents($root . '/app/Web/Social/BookmarkListHandler.php');
        $activity = (string) file_get_contents($root . '/app/Web/Profile/ActivityFeedHandler.php');
        $settings = (string) file_get_contents($root . '/app/Web/Profile/ProfileActivitySettingsHandler.php');

        self::assertStringContainsString('new BookmarkListHandler($socialInteractions, $viewerResolver, $basePath)', $factory);
        self::assertStringContainsString('[HttpMethod::Get, HttpMethod::Post, HttpMethod::Put]', $factory);
        self::assertStringContainsString('new DateTimeZone($config->requireString(\'site.timezone\'))', $factory);
        self::assertStringContainsString("str_contains(\$accept, 'text/html')", $bookmarks);
        self::assertStringContainsString("'items' => array_map(", $bookmarks);
        self::assertStringContainsString("str_contains(strtolower(\$request->headers()->line('accept') ?? ''), 'text/html')", $activity);
        self::assertStringContainsString('HttpMethod::Post', $settings);
        self::assertStringContainsString('save_activity_settings', $settings);
        self::assertStringContainsString('HttpMethod::Put', $settings);
    }

    public function testBookmarkPageLinksToThreadAnchorAndEscapesPrivateNote(): void
    {
        $entry = new BookmarkEntry(
            EntityId::fromString(str_repeat('a', 32)),
            '<private note>',
            EntityId::fromString(str_repeat('b', 32)),
            '<Thread title>',
            7,
        );

        $html = BookmarkListHtml::page([$entry], 1, false, new BasePath('/community'));

        self::assertStringContainsString('&lt;Thread title&gt;', $html);
        self::assertStringContainsString('&lt;private note&gt;', $html);
        self::assertStringContainsString(
            '/community/threads/' . str_repeat('b', 32) . '#post-' . str_repeat('a', 32),
            $html,
        );
        self::assertStringContainsString('Mesaj #7', $html);
    }

    public function testActivityFeedPageUsesLocalizedLabelsAndSafeThreadAction(): void
    {
        $now = new DateTimeImmutable('2026-09-27 18:00:00', new DateTimeZone('UTC'));
        $entry = new ActivityFeedEntry(
            ActivityFeedType::ThreadCreated,
            EntityId::fromString(str_repeat('1', 32)),
            EntityId::fromString(str_repeat('2', 32)),
            EntityId::fromString(str_repeat('3', 32)),
            null,
            null,
            $now,
            '<New thread>',
        );

        $html = ActivityFeedHtml::page(
            [$entry],
            1,
            false,
            new BasePath('/forum'),
            new DateTimeZone('Europe/Istanbul'),
        );

        self::assertStringContainsString('Yeni konu', $html);
        self::assertStringContainsString('&lt;New thread&gt;', $html);
        self::assertStringContainsString('/forum/threads/' . str_repeat('2', 32), $html);
        self::assertStringContainsString('21:00', $html);
    }

    public function testProfileActivitySettingsExposeAllPrivacyScopesAndCsrf(): void
    {
        $html = ProfileActivitySettingsHtml::page(
            new ProfileActivitySettings(ProfileActivityScope::Followers, ProfileActivityScope::OwnerOnly),
            'csrf-value',
            new BasePath('/forum'),
            true,
        );

        self::assertStringContainsString('Profil etkinliği ayarları kaydedildi.', $html);
        self::assertStringContainsString('name="_csrf" value="csrf-value"', $html);
        self::assertStringContainsString('value="everyone"', $html);
        self::assertStringContainsString('value="followers" selected', $html);
        self::assertStringContainsString('value="owner_only" selected', $html);
        self::assertStringContainsString('/forum/activity', $html);
    }

    public function testBookmarkRepositoryLoadsThreadContextWithoutNewMigration(): void
    {
        $root = dirname(__DIR__, 4);
        $repository = (string) file_get_contents($root . '/core/Social/Interaction/DatabaseSocialInteractionRepository.php');

        self::assertStringContainsString('INNER JOIN `forwext_posts` p', $repository);
        self::assertStringContainsString('INNER JOIN `forwext_threads` t', $repository);
        self::assertStringContainsString("t.\`title\` AS \`thread_title\`", $repository);
        self::assertStringContainsString('postPosition', (string) file_get_contents($root . '/core/Social/Interaction/BookmarkEntry.php'));
    }
}
