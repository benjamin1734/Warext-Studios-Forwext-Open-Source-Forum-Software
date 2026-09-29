<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Social\RelationshipAccountHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Social\Interaction\UserRelationshipEntry;
use PHPUnit\Framework\TestCase;

final class ProfileRelationshipsWebSurfaceTest extends TestCase
{
    public function testFactorySharesOneSocialRepositoryAndRegistersRelationshipSurface(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        self::assertSame(1, substr_count($factory, 'new DatabaseSocialInteractionRepository($database)'));
        self::assertStringContainsString('new DatabaseSocialRelationshipReader($database)', $factory);
        self::assertStringContainsString("'account.relationships'", $factory);
        self::assertStringContainsString("new PathTemplate('/account/relationships')", $factory);
        self::assertStringContainsString(
            "'user.follow', [HttpMethod::Post, HttpMethod::Put, HttpMethod::Delete]",
            $factory,
        );
        self::assertStringContainsString(
            "'user.ignore', [HttpMethod::Post, HttpMethod::Put, HttpMethod::Delete]",
            $factory,
        );
        self::assertStringContainsString('$socialRepository,', $factory);
    }

    public function testProfileUsesStoredRelationshipStateAndKeepsAuthenticatedShell(): void
    {
        $root = dirname(__DIR__, 4);
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileViewHandler.php');

        self::assertStringContainsString('isFollowing($viewerId, $profileUserId)', $profile);
        self::assertStringContainsString('isIgnoring($viewerId, $profileUserId)', $profile);
        self::assertStringContainsString('data-user-relationship', $profile);
        self::assertStringContainsString('data-follow-toggle', $profile);
        self::assertStringContainsString('data-ignore-toggle', $profile);
        self::assertStringContainsString('authenticated: $viewerId !== null', $profile);
        self::assertStringContainsString('viewerId: $viewerId?->value()', $profile);
    }

    public function testRelationshipBrowserClientUsesSharedCsrfAndSafeDomUpdates(): void
    {
        $root = dirname(__DIR__, 4);
        $asset = (string) file_get_contents($root . '/public/assets/profile-relationships.js');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-base.css')
            . (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString('/account/interactions/csrf', $asset);
        self::assertStringContainsString("'X-CSRF-Token': token", $asset);
        self::assertStringContainsString("method: 'POST'", $asset);
        self::assertStringContainsString("'follow'", $asset);
        self::assertStringContainsString("'unfollow'", $asset);
        self::assertStringContainsString("'ignore'", $asset);
        self::assertStringContainsString("'unignore'", $asset);
        self::assertStringContainsString("root.dataset.following = '0'", $asset);
        self::assertStringContainsString('textContent =', $asset);
        self::assertStringNotContainsString('innerHTML', $asset);
        self::assertStringContainsString('/assets/profile-relationships.js', $profile);
        self::assertStringContainsString('.profile-relationship-actions', $css);
        self::assertStringContainsString('.relationship-panel', $css);
    }

    public function testRelationshipAccountHtmlRendersListsAndMutationControls(): void
    {
        $now = new DateTimeImmutable('2026-09-27 18:00:00', new DateTimeZone('UTC'));
        $entry = new UserRelationshipEntry(
            EntityId::fromString(str_repeat('a', 32)),
            Username::fromString('Example_User'),
            $now,
        );

        $html = RelationshipAccountHtml::page(
            [$entry],
            [$entry],
            [$entry],
            new BasePath('/forum'),
            new DateTimeZone('Europe/Istanbul'),
        );

        self::assertStringContainsString('Takip Ettiklerin', $html);
        self::assertStringContainsString('Takipçilerin', $html);
        self::assertStringContainsString('Yok Sayılanlar', $html);
        self::assertStringContainsString('/forum/members/Example_User', $html);
        self::assertStringContainsString('data-user-id="' . str_repeat('a', 32) . '"', $html);
        self::assertStringContainsString('data-following="1"', $html);
        self::assertStringContainsString('data-ignoring="1"', $html);
        self::assertStringContainsString('21:00', $html);
        self::assertStringContainsString('surface-head relationship-center-head', $html);
        self::assertStringContainsString('relationship-panel surface-panel', $html);
    }

    public function testAccountDashboardLinksRelationshipManagement(): void
    {
        $root = dirname(__DIR__, 4);
        $dashboard = (string) file_get_contents($root . '/app/Web/Account/AccountDashboardHtml.php');

        self::assertStringContainsString('Sosyal İlişkiler', $dashboard);
        self::assertStringContainsString('/account/relationships', $dashboard);
    }
}
