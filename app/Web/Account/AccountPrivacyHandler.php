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
use Forwext\Core\Presence\PresenceService;
use Forwext\Core\Presence\PresenceVisibility;
use Forwext\Core\Profile\ProfileException;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use ValueError;

final readonly class AccountPrivacyHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileViewerResolver $viewers,
        private ProfileService $profiles,
        private PresenceService $presence,
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
        $profile = $this->profiles->getOrDefault($actor, $now);

        if ($request->method() === HttpMethod::Post) {
            try {
                $updated = $this->submit($request, $profile, $now);
                return Response::redirect(
                    $this->basePath->prepend('/account/privacy?updated=' . rawurlencode($updated)),
                    303,
                )->withHeader('Cache-Control', 'no-store');
            } catch (InvalidArgumentException|ValueError|ProfileException) {
                return $this->view($request, $profile, true);
            }
        }

        $updated = $request->query()['updated'] ?? null;
        return $this->view(
            $request,
            $profile,
            false,
            is_string($updated) ? $updated : null,
        );
    }

    private function submit(Request $request, UserProfile $profile, DateTimeImmutable $now): string
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Privacy action is missing.');
        }

        if ($action === 'save_profile_visibility') {
            $profileVisibility = self::stringField($body, 'profile_visibility');
            $aboutVisibility = self::stringField($body, 'about_visibility');
            $socialVisibility = self::stringField($body, 'social_visibility');
            $mediaVisibility = self::stringField($body, 'media_visibility');

            $this->profiles->update(
                $profile->userId,
                $profile->userId,
                $profile->about,
                ProfileVisibility::from($profileVisibility),
                ProfileVisibility::from($aboutVisibility),
                ProfileVisibility::from($socialVisibility),
                ProfileVisibility::from($mediaVisibility),
                $profile->socialLinks,
                $profile->tabs,
                $now,
            );
            return 'profile';
        }

        if ($action === 'save_presence_visibility') {
            $this->presence->setVisibility(
                $profile->userId,
                PresenceVisibility::from(self::stringField($body, 'presence_visibility')),
            );
            return 'presence';
        }

        throw new InvalidArgumentException('Privacy action is invalid.');
    }

    private function view(
        Request $request,
        UserProfile $profile,
        bool $error,
        ?string $updated = null,
    ): Response {
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        return Response::html(AccountPrivacyHtml::page(
            $profile,
            $this->presence->visibility($profile->userId),
            $token,
            $this->basePath,
            $updated,
            $error,
        ), $error ? 422 : 200)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    /** @param array<string,mixed> $body */
    private static function stringField(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Privacy form is incomplete.');
        }
        return $value;
    }
}
