<?php

declare(strict_types=1);

namespace Forwext\App\Web\Community;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Analytics\AnalyticsEvent;
use Forwext\Core\Analytics\AnalyticsEventRecorder;
use Forwext\Core\Bug\Diagnostic\BugBrowserDeviceClassifier;
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
        private ?AnalyticsEventRecorder $analytics=null,
        private ?BugBrowserDeviceClassifier $devices=null,
        private ?string $sessionCookieName=null,
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
            if ($this->analytics !== null && $this->devices !== null) {
                $session = $this->sessionCookieName === null
                    ? null
                    : $request->cookie($this->sessionCookieName);
                $device = $this->devices->classify(
                    $request->headers()->first('user-agent'),
                )->deviceClass;
                $this->analytics->recordBestEffort(new AnalyticsEvent(
                    'user.active',
                    actorUserId: $actor,
                    sessionId: is_string($session) && $session !== '' ? $session : null,
                    dimensions: ['device' => $device],
                ));
            }
        }
        return (new Response('', 204))->withHeader('Cache-Control', 'no-store');
    }
}
