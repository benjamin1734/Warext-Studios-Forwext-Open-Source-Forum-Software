<?php

declare(strict_types=1);

namespace Forwext\App\Web\Account;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Notification\NotificationException;
use Forwext\Core\Notification\Sound\NotificationSoundService;
use Forwext\Core\Presence\PresenceService;
use Forwext\Core\Profile\Activity\ProfileActivityException;
use Forwext\Core\Profile\Activity\ProfileActivityService;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Routing\BasePath;

final readonly class AccountPreferencesHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileViewerResolver $viewers,
        private ProfileService $profiles,
        private ProfileActivityService $activity,
        private PresenceService $presence,
        private NotificationSoundService $notifications,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {
            $activity = $this->activity->settings($actor, $actor);
        } catch (ProfileActivityException|\Forwext\Core\Domain\Access\Permission\PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $notifications = $this->notifications->settings($actor, $now);
        } catch (NotificationException|\Forwext\Core\Domain\Access\Permission\PermissionDeniedException) {
            $notifications = null;
        }

        return Response::html(AccountPreferencesHtml::page(
            $this->profiles->getOrDefault($actor, $now),
            $activity,
            $this->presence->visibility($actor),
            $notifications,
            $this->basePath,
        ))->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }
}
