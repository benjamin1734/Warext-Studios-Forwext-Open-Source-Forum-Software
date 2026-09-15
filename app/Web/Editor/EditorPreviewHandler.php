<?php

declare(strict_types=1);

namespace Forwext\App\Web\Editor;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Forum\Editor\EditorPreviewService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use InvalidArgumentException;

final readonly class EditorPreviewHandler implements RequestHandlerInterface
{
    public function __construct(
        private EditorPreviewService $preview,
        private ProfileViewerResolver $viewers,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($this->viewers->resolve($request) === null) {
            return Response::json(['error' => 'authentication_required'], 401)
                ->withHeader('Cache-Control', 'no-store');
        }

        $source = $request->parsedBody()['source'] ?? null;
        if (!is_string($source)) {
            return Response::json(['error' => 'invalid_source'], 400)
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            $result = $this->preview->preview($source);
        } catch (InvalidArgumentException) {
            return Response::json(['error' => 'invalid_source'], 422)
                ->withHeader('Cache-Control', 'no-store');
        }

        return Response::json($result->toArray())
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
