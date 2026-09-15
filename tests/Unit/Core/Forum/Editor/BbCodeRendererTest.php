<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Editor;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Editor\BbCodeRenderer;
use Forwext\Core\Forum\Editor\MentionResolver;
use Forwext\Core\Forum\Editor\MentionTarget;
use Forwext\Core\Forum\Editor\SafeEditorLinkPolicy;
use Forwext\Core\Forum\Editor\SafeLinkEmbedResolver;
use PHPUnit\Framework\TestCase;

final class BbCodeRendererTest extends TestCase
{
    public function testRendererEscapesRawHtmlAndRendersNestedFormatting(): void
    {
        $html = $this->renderer()->render('<script>alert(1)</script> [b]Bold [i]inner[/i][/b]');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('<strong>Bold <em>inner</em></strong>', $html);
    }

    public function testJavascriptUrlsNeverBecomeLinks(): void
    {
        $html = $this->renderer()->render('[url=javascript:alert(1)]Click[/url]');

        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringContainsString('fx-bbcode-link--invalid', $html);
        self::assertStringContainsString('Click', $html);
    }

    public function testQuoteLabelsAndCodeBodiesAreEscaped(): void
    {
        $html = $this->renderer()->render(
            '[quote=<img src=x onerror=alert(1)>]Hello[/quote]'
            . '[code][b]<img src=x onerror=alert(1)>[/b][/code]',
        );

        self::assertStringNotContainsString('<img ', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('[b]&lt;img src=x onerror=alert(1)&gt;[/b]', $html);
        self::assertStringNotContainsString('<strong><img', $html);
    }

    public function testMentionUsesCanonicalResolverAndEmbedUsesSafeCard(): void
    {
        $id = str_repeat('1', 32);
        $html = $this->renderer()->render(
            '[mention=' . $id . '] [embed]https://example.com/video/1[/embed]',
        );

        self::assertStringContainsString('href="/members/CanonicalUser"', $html);
        self::assertStringContainsString('@CanonicalUser', $html);
        self::assertStringContainsString('class="fx-embed"', $html);
        self::assertStringContainsString('https://example.com/video/1', $html);
        self::assertStringContainsString('rel="nofollow ugc noopener"', $html);
    }

    public function testInvalidEmbedRemainsNonExecutableText(): void
    {
        $html = $this->renderer()->render('[embed]javascript:alert(1)[/embed]');

        self::assertStringContainsString('fx-embed--invalid', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
    }

    public function testExcessiveNestingDoesNotCreateUnboundedRecursion(): void
    {
        $source = str_repeat('[b]', 40) . 'safe' . str_repeat('[/b]', 40);
        $html = $this->renderer()->render($source);

        self::assertStringContainsString('safe', $html);
        self::assertStringContainsString('[b]', $html);
        self::assertLessThan(120000, strlen($html));
    }

    private function renderer(): BbCodeRenderer
    {
        $links = new SafeEditorLinkPolicy();
        return new BbCodeRenderer(
            $links,
            new class implements MentionResolver {
                public function resolve(EntityId $userId): ?MentionTarget
                {
                    return $userId->value() === str_repeat('1', 32)
                        ? new MentionTarget('@CanonicalUser', '/members/CanonicalUser')
                        : null;
                }
            },
            new SafeLinkEmbedResolver($links),
        );
    }
}
