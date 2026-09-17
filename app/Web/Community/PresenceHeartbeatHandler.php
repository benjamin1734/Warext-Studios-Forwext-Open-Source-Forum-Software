<?php

declare(strict_types=1);

namespace Forwext\App\Web\Community;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Presence\PresenceService;

final readonly class PresenceHeartbeatHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileViewerResolver $viewers,
        private PresenceService $presence,
        private PresenceRequestGuard $guard,
    ) {
    }

    public function handle(Request $request): Response
    {
        if (!$this->guard->allows($request)) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        }
        $actor = $this->viewers->resolve($request);
        if ($actor !== null) {
            $this->presence->heartbeat($actor);
        }
        return (new Response('', 204))->withHeader('Cache-Control', 'no-store');
    }
}
