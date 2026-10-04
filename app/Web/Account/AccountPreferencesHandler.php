<?php

declare(strict_types=1);

namespace Forwext\App\Web\Account;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Notification\NotificationException;
use Forwext\Core\Notification\Sound\NotificationSoundService;
use Forwext\Core\Notification\Sound\NotificationSoundSettings;
use Forwext\Core\Presence\PresenceService;
use Forwext\Core\Presence\PresenceVisibility;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use ValueError;

final readonly class AccountPreferencesHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileViewerResolver $viewers,
        private ProfileService $profiles,
        private PresenceService $presence,
        private NotificationSoundService $notificationSounds,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)
                ->withHeader('Cache-Control', 'no-store');
        }

        if ($request->method() === HttpMethod::Post) {
            try {
                $body = $request->parsedBody();
                if (($body['action'] ?? null) !== 'save_presence') {
                    throw new InvalidArgumentException('Account preference action is invalid.');
                }
                $value = $body['presence_visibility'] ?? null;
                if (!is_string($value)) {
                    throw new InvalidArgumentException('Presence visibility is invalid.');
                }
                $this->presence->setVisibility($actor, PresenceVisibility::from($value));

                return Response::redirect(
                    $this->basePath->prepend('/account/preferences?updated=presence'),
                    303,
                )->withHeader('Cache-Control', 'no-store');
            } catch (InvalidArgumentException|ValueError) {
                return Response::text('Bad Request', 400)
                    ->withHeader('Cache-Control', 'no-store')
                    ->withHeader('X-Robots-Tag', 'noindex,nofollow');
            }
        }

        $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($csrf) || $csrf === '') {
            return Response::text('Internal Server Error', 500)
                ->withHeader('Cache-Control', 'no-store');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $profile = $this->profiles->getOrDefault($actor, $now);
        $presence = $this->presence->visibility($actor);
        $notificationSettings = $this->notificationSettings($actor, $now);

        return Response::html(AccountPreferencesHtml::page(
            $profile,
            $presence,
            $notificationSettings,
            $csrf,
            $this->basePath,
            ($request->query()['updated'] ?? null) === 'presence',
        ))->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    private function notificationSettings(
        \Forwext\Core\Domain\Entity\EntityId $actor,
        DateTimeImmutable $now,
    ): ?NotificationSoundSettings {
        try {
            return $this->notificationSounds->settings($actor, $now);
        } catch (NotificationException) {
            return null;
        }
    }
}
