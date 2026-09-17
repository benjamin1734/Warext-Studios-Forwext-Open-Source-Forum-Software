<?php

declare(strict_types=1);

namespace Forwext\App\Web\Community;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Presence\PresenceService;
use Forwext\Core\Presence\PresenceVisibility;
use ValueError;

final readonly class PresencePreferenceHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileViewerResolver $viewers,
        private PresenceService $presence,
        private PresenceRequestGuard $guard,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::json(['error' => 'authentication_required'], 401)
                ->withHeader('Cache-Control', 'private, no-store');
        }

        if ($request->method() === HttpMethod::Get) {
            return Response::json(['visibility' => $this->presence->visibility($actor)->value])
                ->withHeader('Cache-Control', 'private, no-store');
        }
        if (!$this->guard->allows($request)) {
            return Response::json(['error' => 'forbidden'], 403)
                ->withHeader('Cache-Control', 'private, no-store');
        }

        $value = $request->parsedBody()['visibility'] ?? null;
        if (!is_string($value)) {
            return Response::json(['error' => 'invalid_visibility'], 400)
                ->withHeader('Cache-Control', 'private, no-store');
        }
        try {
            $visibility = PresenceVisibility::from($value);
        } catch (ValueError) {
            return Response::json(['error' => 'invalid_visibility'], 400)
                ->withHeader('Cache-Control', 'private, no-store');
        }
        $this->presence->setVisibility($actor, $visibility);

        return Response::json(['visibility' => $visibility->value])
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
