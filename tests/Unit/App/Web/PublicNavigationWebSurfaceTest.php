<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Navigation\NavigationRuntime;
use PHPUnit\Framework\TestCase;

final class PublicNavigationWebSurfaceTest extends TestCase
{
    protected function tearDown(): void
    {
        NavigationRuntime::reset();
    }

    public function testGeneratedNavigationConfigurationChangesTheSharedShellWithoutClientSideAuthorization(): void
    {
        NavigationRuntime::configure([
            'forums' => [
                'label' => 'Topluluk',
                'path' => '/forums',
                'order' => 50,
                'audience' => 'public',
                'placement' => 'primary',
                'enabled' => true,
            ],
            'faq' => ['enabled' => false],
            'custom.wiki' => [
                'label' => 'Wiki',
                'path' => '/wiki',
                'order' => 55,
                'audience' => 'public',
                'placement' => 'primary',
                'enabled' => true,
            ],
            'custom.staff' => [
                'label' => 'Ekip',
                'path' => '/staff',
                'order' => 500,
                'audience' => 'member',
                'placement' => 'more',
                'enabled' => true,
            ],
        ]);

        $guest = ProfileHtml::page('Guest', '<p>Body</p>', new BasePath('/community'));
        self::assertStringContainsString('>Topluluk</a>', $guest);
        self::assertStringContainsString('href="/community/wiki"', $guest);
        self::assertStringNotContainsString('href="/community/faq"', $guest);
        self::assertStringNotContainsString('href="/community/staff"', $guest);

        $member = ProfileHtml::page(
            'Member',
            '<p>Body</p>',
            new BasePath('/community'),
            authenticated: true,
        );
        self::assertStringContainsString('href="/community/staff"', $member);
        self::assertStringContainsString('data-nav-section-link="custom-custom-wiki"', $member);
        self::assertStringContainsString('href="/community/account/profile"', $member);
        self::assertStringContainsString('href="/community/account/sessions"', $member);
        self::assertStringContainsString('href="/community/account/bookmarks"', $member);
        self::assertStringContainsString('href="/community/account/relationships"', $member);
        self::assertStringContainsString('href="/community/account/notification-settings"', $member);
        self::assertStringContainsString('href="/community/account/presence"', $member);
        self::assertStringContainsString('/community/account/conversations#new-conversation', $member);
        self::assertStringNotContainsString('href="/community/account/profile"', $guest);
    }

    public function testNavigationManagerSurfaceIsServerRenderedAndHasNoInlineScript(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Admin/PublicNavigationHtml.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/PublicNavigationHandler.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        self::assertStringContainsString('name="label"', $html);
        self::assertStringContainsString('name="path"', $html);
        self::assertStringContainsString('name="order"', $html);
        self::assertStringContainsString('name="audience"', $html);
        self::assertStringContainsString('name="placement"', $html);
        self::assertStringContainsString('name="enabled"', $html);
        self::assertStringContainsString('value="reset"', $html);
        self::assertStringContainsString('value="delete"', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('HttpAuditRequestId::fromRequest', $handler);
        self::assertStringContainsString('/* public-navigation-manager-v1 */', $css);
        self::assertSame(substr_count($css, '{'), substr_count($css, '}'));
    }
}
