<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileContentHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Profile\Content\UserForumContentItem;
use Forwext\Core\Profile\Content\UserForumContentType;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class ProfileContentWebSurfaceTest extends TestCase
{
    public function testMemberContentSurfaceEscapesPostExcerptAndProvidesStableTabs(): void
    {
        $html = ProfileContentHtml::page(
            'ci-admin',
            [new UserForumContentItem(
                UserForumContentType::Post,
                EntityId::fromString(str_repeat('b', 32)),
                EntityId::fromString(str_repeat('a', 32)),
                EntityId::fromString(str_repeat('1', 32)),
                'General',
                '<script>thread</script>',
                '<img src=x onerror=alert(1)>',
                3,
                new DateTimeImmutable('2026-10-04T10:00:00+00:00'),
                new DateTimeImmutable('2026-10-04T11:00:00+00:00'),
            )],
            UserForumContentType::Post,
            1,
            false,
            new BasePath('/community'),
            new DateTimeZone('Europe/Istanbul'),
        );

        self::assertStringContainsString('href="/community/members/ci-admin/content/threads"', $html);
        self::assertStringContainsString('aria-current="page" href="/community/members/ci-admin/content/posts"', $html);
        self::assertStringContainsString('href="/community/threads/' . str_repeat('a', 32) . '#post-' . str_repeat('b', 32) . '"', $html);
        self::assertStringContainsString('&lt;script&gt;thread&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<script>thread</script>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    public function testMemberContentRouteUsesProfileVisibilityAndForumScopeReader(): void
    {
        $root = dirname(__DIR__, 5);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Profile/ProfileContentHandler.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileViewHandler.php');
        $reader = (string) file_get_contents($root . '/core/Profile/Content/DatabaseUserForumContentReader.php');

        self::assertStringContainsString("new PathTemplate('/members/{username}/content/{kind}'", $factory);
        self::assertStringContainsString("'kind'=>'threads|posts'", $factory);
        self::assertStringContainsString('new DatabaseUserForumContentReader($database, $forumScopeProvider)', $factory);
        self::assertStringContainsString('$this->profiles->visibleProfile(', $handler);
        self::assertStringContainsString('$viewerId = $this->viewers->resolve($request);', $handler);
        self::assertStringContainsString("'/content/threads'", $profile);
        self::assertStringContainsString("'/content/posts'", $profile);
        self::assertStringContainsString('$this->forumScopes->scopes($viewerUserId)', $reader);
        self::assertStringContainsString("t.`moderation_state` = 'visible'", $reader);
        self::assertStringContainsString("p.`moderation_state` = 'visible'", $reader);
    }
}
