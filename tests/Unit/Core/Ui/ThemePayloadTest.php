<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Theme\ThemePayload;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ThemePayloadTest extends TestCase
{
    public function testChildPayloadOverridesTemplatesPhrasesAndExtendsAssets(): void
    {
        $parent = new ThemePayload(
            ['page.shell' => '<h1>{{ title }}</h1>', 'page.footer' => 'Parent footer'],
            ['tr' => ['nav.home' => 'Ana Sayfa', 'nav.search' => 'Ara']],
            'body{font-family:system-ui}',
            'globalThis.parentTheme=true;',
        );
        $child = new ThemePayload(
            ['page.footer' => 'Child footer'],
            ['tr' => ['nav.search' => 'Bul'], 'en-US' => ['nav.home' => 'Home']],
            '.child{display:block}',
            'globalThis.childTheme=true;',
        );

        $merged = ThemePayload::merge($parent, $child);

        self::assertSame('<h1>{{ title }}</h1>', $merged->templates['page.shell']);
        self::assertSame('Child footer', $merged->templates['page.footer']);
        self::assertSame('Ana Sayfa', $merged->phrase('tr', 'nav.home'));
        self::assertSame('Bul', $merged->phrase('tr', 'nav.search'));
        self::assertSame('Home', $merged->phrase('en-US', 'nav.home'));
        self::assertStringContainsString('font-family:system-ui', $merged->customCss);
        self::assertStringContainsString('.child{display:block}', $merged->customCss);
        self::assertStringContainsString('parentTheme', $merged->customJs);
        self::assertStringContainsString('childTheme', $merged->customJs);
    }

    public function testPhraseFallsBackToTurkish(): void
    {
        $payload = new ThemePayload([], ['tr' => ['forum.empty' => 'Henüz içerik yok.']]);

        self::assertSame('Henüz içerik yok.', $payload->phrase('en-US', 'forum.empty'));
        self::assertNull($payload->phrase('en-US', 'missing.key'));
    }

    public function testCustomCssRejectsExternalExecutableOrImportUrls(): void
    {
        foreach ([
            '@import url("https://evil.invalid/a.css");',
            '.x{background:url(https://evil.invalid/a.png)}',
            '.x{background:url(javascript:alert(1))}',
            '.x{background:url(data:text/html,test)}',
        ] as $css) {
            try {
                new ThemePayload([], [], $css);
                self::fail('Unsafe custom CSS was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCustomJsRejectsScriptClosingSequence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ThemePayload([], [], '', 'console.log(1);</script><script>alert(1)</script>');
    }
}
