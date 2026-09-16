<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Editor;

use Forwext\App\Web\Editor\EditorLinkPreviewHandler;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Editor\ApprovedLinkPreviewUrl;
use Forwext\Core\Forum\Editor\HostAddressResolver;
use Forwext\Core\Forum\Editor\LinkPreviewHttpResponse;
use Forwext\Core\Forum\Editor\LinkPreviewService;
use Forwext\Core\Forum\Editor\LinkPreviewTransport;
use Forwext\Core\Forum\Editor\LinkPreviewUrlPolicy;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class EditorLinkPreviewHandlerTest extends TestCase
{
    public function testOutboundPreviewRequiresAuthenticatedEditorHeader(): void
    {
        $transport = new WebPreviewTransport();
        $handler = $this->handler($transport, $this->id('1'));
        $response = $handler->handle(new Request(
            HttpMethod::Post,
            '/editor/link-preview',
            parsedBody: ['url' => 'https://public.example.com/page'],
        ));

        self::assertSame(403, $response->status());
        self::assertSame(0, $transport->calls);
    }

    public function testOutboundPreviewRequiresAuthenticatedViewer(): void
    {
        $transport = new WebPreviewTransport();
        $handler = $this->handler($transport, null);
        $response = $handler->handle(new Request(
            HttpMethod::Post,
            '/editor/link-preview',
            new HeaderBag(['X-Forwext-Editor' => '1']),
            parsedBody: ['url' => 'https://public.example.com/page'],
        ));

        self::assertSame(401, $response->status());
        self::assertSame(0, $transport->calls);
    }

    public function testValidEditorPreviewReturnsOnlyCuratedMetadata(): void
    {
        $transport = new WebPreviewTransport();
        $handler = $this->handler($transport, $this->id('1'));
        $response = $handler->handle(new Request(
            HttpMethod::Post,
            '/editor/link-preview',
            new HeaderBag(['X-Forwext-Editor' => '1']),
            parsedBody: ['url' => 'https://public.example.com/page'],
        ));

        self::assertSame(200, $response->status());
        self::assertSame(1, $transport->calls);
        $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Preview title', $payload['title']);
        self::assertSame('Preview description', $payload['description']);
        self::assertSame('public.example.com', $payload['host']);
        self::assertArrayNotHasKey('body', $payload);
        self::assertSame('private, no-store', $response->headers()->first('cache-control'));
    }

    private function handler(WebPreviewTransport $transport, ?EntityId $viewer): EditorLinkPreviewHandler
    {
        return new EditorLinkPreviewHandler(
            new LinkPreviewService(
                new LinkPreviewUrlPolicy(new WebPreviewResolver()),
                $transport,
            ),
            new LinkPreviewViewerResolver($viewer),
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}

final class WebPreviewTransport implements LinkPreviewTransport
{
    public int $calls = 0;

    public function fetch(ApprovedLinkPreviewUrl $url, int $maxBytes = 262144, int $timeoutSeconds = 4): LinkPreviewHttpResponse
    {
        ++$this->calls;
        return new LinkPreviewHttpResponse(
            200,
            ['content-type' => 'text/html'],
            '<html><head><title>Preview title</title>'
            . '<meta name="description" content="Preview description"></head><body>ignored</body></html>',
        );
    }
}

final class WebPreviewResolver implements HostAddressResolver
{
    public function resolve(string $host): array
    {
        return $host === 'public.example.com' ? ['93.184.216.34'] : [];
    }
}

final readonly class LinkPreviewViewerResolver implements ProfileViewerResolver
{
    public function __construct(private ?EntityId $viewer) {}
    public function resolve(Request $request): ?EntityId { return $this->viewer; }
}
