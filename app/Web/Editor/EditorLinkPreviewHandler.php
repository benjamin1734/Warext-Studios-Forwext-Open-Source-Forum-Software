<?php

declare(strict_types=1);

namespace Forwext\App\Web\Editor;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Forum\Editor\LinkPreviewException;
use Forwext\Core\Forum\Editor\LinkPreviewService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Throwable;

final readonly class EditorLinkPreviewHandler implements RequestHandlerInterface
{
    public function __construct(
        private LinkPreviewService $previews,
        private ProfileViewerResolver $viewers,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($this->viewers->resolve($request) === null) {
            return Response::json(['error' => 'authentication_required'], 401)
                ->withHeader('Cache-Control', 'no-store');
        }
        if ($request->headers()->first('X-Forwext-Editor') !== '1') {
            return Response::json(['error' => 'editor_request_required'], 403)
                ->withHeader('Cache-Control', 'no-store');
        }
        $raw = $request->parsedBody()['url'] ?? null;
        if (!is_string($raw)) {
            return Response::json(['error' => 'invalid_url'], 422)
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            $preview = $this->previews->preview($raw);
        } catch (LinkPreviewException) {
            return Response::json(['error' => 'preview_unavailable'], 422)
                ->withHeader('Cache-Control', 'no-store');
        } catch (Throwable) {
            return Response::json(['error' => 'preview_unavailable'], 502)
                ->withHeader('Cache-Control', 'no-store');
        }

        return Response::json([
            'url' => $preview->url,
            'host' => $preview->host,
            'title' => $preview->title,
            'description' => $preview->description,
        ])->withHeader('Cache-Control', 'private, no-store');
    }
}
