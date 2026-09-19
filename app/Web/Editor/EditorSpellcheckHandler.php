<?php

declare(strict_types=1);

namespace Forwext\App\Web\Editor;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Content\Spellcheck\SpellcheckAccessDeniedException;
use Forwext\Core\Content\Spellcheck\SpellcheckService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use InvalidArgumentException;

final readonly class EditorSpellcheckHandler implements RequestHandlerInterface
{
    public function __construct(
        private SpellcheckService $spellcheck,
        private ProfileViewerResolver $viewers,
    ) {
    }

    public function handle(Request $request): Response
    {
        $viewerId = $this->viewers->resolve($request);
        if ($viewerId === null) {
            return Response::json(['error'=>'authentication_required'], 401)
                ->withHeader('Cache-Control', 'no-store');
        }

        $source = $request->parsedBody()['source'] ?? null;
        $language = $request->parsedBody()['language'] ?? 'tr-tr';
        if (!is_string($source) || !is_string($language)) {
            return Response::json(['error'=>'invalid_request'], 400)
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            $result = $this->spellcheck->check($viewerId, $source, $language);
        } catch (SpellcheckAccessDeniedException) {
            return Response::json(['error'=>'permission_denied'], 403)
                ->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::json(['error'=>'invalid_source'], 422)
                ->withHeader('Cache-Control', 'no-store');
        }

        return Response::json($result->toArray())
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
