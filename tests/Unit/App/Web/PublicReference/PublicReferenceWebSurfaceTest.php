<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\PublicReference;

use Forwext\App\Web\PublicReference\PublicReferenceHtml;
use Forwext\Core\Forum\Editor\BbCodeReferenceCatalog;
use Forwext\Core\Forum\Editor\EmojiCatalog;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class PublicReferenceWebSurfaceTest extends TestCase
{
    public function testHelpLandingAndReferencePagesExposeRealRoutes(): void
    {
        $basePath = new BasePath('/community');

        $index = PublicReferenceHtml::index($basePath);
        self::assertStringContainsString('/community/faq', $index);
        self::assertStringContainsString('/community/support/new', $index);
        self::assertStringContainsString('/community/help/bb-codes', $index);
        self::assertStringContainsString('/community/help/smilies', $index);
        self::assertStringContainsString('/community/help/trophies', $index);
        self::assertStringContainsString('/community/help/rss', $index);

        $feeds = PublicReferenceHtml::feeds($basePath);
        self::assertStringContainsString('/community/feed.rss', $feeds);
        self::assertStringContainsString('/community/feed.atom', $feeds);

        $contact = PublicReferenceHtml::contact($basePath);
        self::assertStringContainsString('/community/support/new', $contact);
        self::assertStringContainsString('/community/bugs/report', $contact);
    }

    public function testEditorReferencesComeFromFirstPartyCatalogs(): void
    {
        $basePath = new BasePath('');
        $bbcodes = PublicReferenceHtml::bbCodes(BbCodeReferenceCatalog::all(), $basePath);
        $smilies = PublicReferenceHtml::smilies(EmojiCatalog::all(), $basePath);

        foreach (['[b]metin[/b]','[quote=Kullanıcı]','[emoji=smile]','[embed]'] as $needle) {
            self::assertStringContainsString($needle, html_entity_decode($bbcodes));
        }
        foreach ([':smile:', ':fire:', ':party:'] as $needle) {
            self::assertStringContainsString($needle, $smilies);
        }
    }

    public function testPhase10RoutesFooterAndDiscoveryStayWired(): void
    {
        $root = dirname(__DIR__, 5);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $footer = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $discovery = (string) file_get_contents($root . '/core/Seo/Discovery/StaticPublicDiscoverySource.php');
        $renderer = (string) file_get_contents($root . '/core/Forum/Editor/BbCodeRenderer.php');

        foreach ([
            '/help',
            '/help/contact',
            '/help/terms',
            '/help/privacy',
            '/help/cookies',
            '/help/bb-codes',
            '/help/smilies',
            '/help/trophies',
            '/help/rss',
        ] as $route) {
            self::assertStringContainsString("new PathTemplate('" . $route . "')", $factory);
        }

        self::assertStringContainsString("class=\"footer-reference-links\"", $footer);
        self::assertStringContainsString("'/help/privacy'", $discovery);
        self::assertStringContainsString("'/help/trophies'", $discovery);

        foreach (BbCodeReferenceCatalog::all() as $entry) {
            self::assertStringContainsString("'" . $entry['tag'] . "'", $renderer);
        }
    }
}
