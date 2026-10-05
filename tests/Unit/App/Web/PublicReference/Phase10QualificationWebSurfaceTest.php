<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\PublicReference;

use PHPUnit\Framework\TestCase;

final class Phase10QualificationWebSurfaceTest extends TestCase
{
    public function testLiveBrowserCoversPublicReferenceFamilyAndFeeds(): void
    {
        $root = dirname(__DIR__, 5);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

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
            '/feed.rss',
            '/feed.atom',
        ] as $route) {
            self::assertStringContainsString($route, $live);
        }

        self::assertStringContainsString('help mobile', $live);
        self::assertStringContainsString('help smilies mobile', $live);
        self::assertStringContainsString('application/rss+xml', $live);
        self::assertStringContainsString('application/atom+xml', $live);
    }

    public function testPublicReferenceStylesUseSupportedResponsiveBreakpoints(): void
    {
        $root = dirname(__DIR__, 5);
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('public-reference-v1', $css);
        self::assertStringContainsString('@media(max-width:760px)', $css);
        self::assertStringContainsString('@media(max-width:560px)', $css);
        self::assertStringContainsString('@media(pointer:coarse)', $css);
        self::assertStringNotContainsString('@media(max-width:900px)', $css);
    }
}
