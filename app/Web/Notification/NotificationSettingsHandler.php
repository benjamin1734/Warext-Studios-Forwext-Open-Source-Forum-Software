<?php

declare(strict_types=1);

namespace Forwext\App\Web\Notification;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Notification\NotificationException;
use Forwext\Core\Notification\Sound\NotificationSoundService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class NotificationSettingsHandler implements RequestHandlerInterface
{
    public function __construct(
        private NotificationSoundService $sounds,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            if ($request->method() === HttpMethod::Post) {
                return $this->save($actor, $request);
            }

            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            return Response::html(NotificationSettingsHtml::page(
                $this->sounds->settings($actor),
                $this->sounds->presets(),
                $csrf,
                $this->basePath,
                ($request->query()['updated'] ?? null) === '1',
            ))->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (NotificationException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function save(\Forwext\Core\Domain\Entity\EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        if (($body['action'] ?? null) !== 'save_sound') {
            throw new InvalidArgumentException('Notification settings action is invalid.');
        }

        $volume = $body['volume'] ?? null;
        if (is_string($volume) && preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $volume) === 1) {
            $volume = (int) $volume;
        }
        if (!is_int($volume) || $volume < 0 || $volume > 100) {
            throw new InvalidArgumentException('Notification volume is invalid.');
        }

        $soundKey = $body['default_sound_key'] ?? null;
        if (!is_string($soundKey) || preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $soundKey) !== 1) {
            throw new InvalidArgumentException('Notification sound key is invalid.');
        }

        $this->sounds->updateSettings(
            $actor,
            ($body['muted'] ?? null) === '1',
            $volume,
            $soundKey,
        );

        return Response::redirect(
            $this->basePath->prepend('/account/notification-settings?updated=1'),
            303,
        )->withHeader('Cache-Control', 'no-store');
    }
}
