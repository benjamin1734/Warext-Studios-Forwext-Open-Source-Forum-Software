<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Editor;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Editor\ApprovedLinkPreviewUrl;
use Forwext\Core\Forum\Editor\BbCodeRenderer;
use Forwext\Core\Forum\Editor\HostAddressResolver;
use Forwext\Core\Forum\Editor\LinkPreviewException;
use Forwext\Core\Forum\Editor\LinkPreviewHttpResponse;
use Forwext\Core\Forum\Editor\LinkPreviewService;
use Forwext\Core\Forum\Editor\LinkPreviewTransport;
use Forwext\Core\Forum\Editor\LinkPreviewUrlPolicy;
use Forwext\Core\Forum\Editor\MentionResolver;
use Forwext\Core\Forum\Editor\MentionTarget;
use Forwext\Core\Forum\Editor\SafeEditorLinkPolicy;
use Forwext\Core\Forum\Editor\SafeLinkEmbedResolver;
use PHPUnit\Framework\TestCase;

final class EditorSocialSafetyTest extends TestCase
{
    public function testEmojiAliasesRenderButCodeRemainsLiteral(): void
    {
        $html = $this->renderer()->render('Merhaba :) [emoji=fire] [code]:) [emoji=fire][/code]');

        self::assertStringContainsString('aria-label="Gülümseme"', $html);
        self::assertStringContainsString('aria-label="Ateş"', $html);
        self::assertStringContainsString('<code>:) [emoji=fire]</code>', $html);
    }

    public function testPlain64QuotePayloadCannotBreakOutIntoBbCode(): void
    {
        $payload = '[/quote][b]owned[/b]<script>alert(1)</script>';
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $html = $this->renderer()->render('[quote=User][plain64=' . $encoded . '][/quote]');

        self::assertSame(1, substr_count($html, '<blockquote'));
        self::assertStringContainsString('[/quote][b]owned[/b]&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<strong>owned</strong>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testLinkPreviewPolicyAcceptsOnlyAllPublicResolvedAddresses(): void
    {
        $policy = new LinkPreviewUrlPolicy(new MapAddressResolver([
            'public.example.com' => ['93.184.216.34', '2606:4700:4700::1111'],
        ]));

        $approved = $policy->approve('https://public.example.com/path?q=1');

        self::assertSame('public.example.com', $approved->host);
        self::assertSame('/path?q=1', $approved->requestTarget);
        self::assertSame(443, $approved->port);
    }

    public function testLinkPreviewPolicyRejectsPrivateReservedOrMixedResolution(): void
    {
        $cases = [
            ['127.0.0.1'],
            ['10.0.0.1'],
            ['169.254.169.254'],
            ['::1'],
            ['93.184.216.34', '10.1.2.3'],
        ];

        foreach ($cases as $addresses) {
            $policy = new LinkPreviewUrlPolicy(new MapAddressResolver(['blocked.example.com' => $addresses]));
            try {
                $policy->approve('https://blocked.example.com/resource');
                self::fail('Expected non-public or mixed DNS result to be rejected.');
            } catch (LinkPreviewException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLinkPreviewPolicyRejectsIpLiteralAndCredentialedUrls(): void
    {
        $policy = new LinkPreviewUrlPolicy(new MapAddressResolver([]));
        foreach (['https://127.0.0.1/', 'https://user:pass@example.com/', 'http://example.com/'] as $url) {
            try {
                $policy->approve($url);
                self::fail('Expected unsafe URL to be rejected: ' . $url);
            } catch (LinkPreviewException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRedirectIsRevalidatedAndCannotPivotToPrivateHost(): void
    {
        $resolver = new MapAddressResolver([
            'public.example.com' => ['93.184.216.34'],
            'internal.example.com' => ['10.0.0.5'],
        ]);
        $transport = new QueuePreviewTransport([
            new LinkPreviewHttpResponse(302, ['location' => 'https://internal.example.com/secret'], ''),
        ]);
        $service = new LinkPreviewService(new LinkPreviewUrlPolicy($resolver), $transport);

        $this->expectException(LinkPreviewException::class);
        $service->preview('https://public.example.com/start');
    }

    public function testLinkPreviewExtractsOnlyBoundedTextMetadata(): void
    {
        $resolver = new MapAddressResolver(['public.example.com' => ['93.184.216.34']]);
        $transport = new QueuePreviewTransport([
            new LinkPreviewHttpResponse(
                200,
                ['content-type' => 'text/html; charset=utf-8'],
                '<html><head><title> Safe &amp; Title </title>'
                . '<meta name="description" content="A useful description"></head>'
                . '<body><script>bad()</script></body></html>',
            ),
        ]);
        $preview = (new LinkPreviewService(new LinkPreviewUrlPolicy($resolver), $transport))
            ->preview('https://public.example.com/page');

        self::assertSame('Safe & Title', $preview->title);
        self::assertSame('A useful description', $preview->description);
        self::assertSame('public.example.com', $preview->host);
    }

    private function renderer(): BbCodeRenderer
    {
        $links = new SafeEditorLinkPolicy();
        return new BbCodeRenderer(
            $links,
            new class implements MentionResolver {
                public function resolve(EntityId $userId): ?MentionTarget { return null; }
            },
            new SafeLinkEmbedResolver($links),
        );
    }
}

final readonly class MapAddressResolver implements HostAddressResolver
{
    /** @param array<string,list<string>> $map */
    public function __construct(private array $map) {}

    public function resolve(string $host): array
    {
        return $this->map[$host] ?? [];
    }
}

final class QueuePreviewTransport implements LinkPreviewTransport
{
    /** @param list<LinkPreviewHttpResponse> $responses */
    public function __construct(private array $responses) {}

    public function fetch(ApprovedLinkPreviewUrl $url, int $maxBytes = 262144, int $timeoutSeconds = 4): LinkPreviewHttpResponse
    {
        $response = array_shift($this->responses);
        if (!$response instanceof LinkPreviewHttpResponse) {
            throw new LinkPreviewException('Fixture response missing.');
        }
        return $response;
    }
}
