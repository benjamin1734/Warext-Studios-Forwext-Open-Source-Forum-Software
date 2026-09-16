<?php

declare(strict_types=1);

namespace Forwext\App\Web\Social;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;

final readonly class InteractionCsrfTokenHandler implements RequestHandlerInterface
{
    public function __construct(private ProfileViewerResolver $viewers)
    {
    }

    public function handle(Request $request): Response
    {
        if ($this->viewers->resolve($request) === null) {
            return Response::json(['error' => 'authentication_required'], 401)
                ->withHeader('Cache-Control', 'no-store');
        }
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::json(['error' => 'csrf_token_unavailable'], 500)
                ->withHeader('Cache-Control', 'no-store');
        }
        return Response::json(['csrf_token' => $token])
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
