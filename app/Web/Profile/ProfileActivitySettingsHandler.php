<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Profile\Activity\ProfileActivityException;
use Forwext\Core\Profile\Activity\ProfileActivityScope;
use Forwext\Core\Profile\Activity\ProfileActivityService;
use Forwext\Core\Profile\Activity\ProfileActivitySettings;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ProfileActivitySettingsHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileActivityService $service,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {}

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);
        try {
            if ($request->method() === HttpMethod::Get) {
                $settings = $this->service->settings($actor, $actor);
                if ($this->wantsHtml($request)) {
                    $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
                    if (!is_string($csrf) || $csrf === '') {
                        return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
                    }
                    return Response::html(ProfileActivitySettingsHtml::page(
                        $settings,
                        $csrf,
                        $this->basePath,
                        ($request->query()['updated'] ?? null) === '1',
                    ))->withHeader('Cache-Control', 'private, no-store')
                        ->withHeader('X-Robots-Tag', 'noindex,nofollow');
                }
            } elseif ($request->method() === HttpMethod::Put || $request->method() === HttpMethod::Post) {
                $body = $request->parsedBody();
                if ($request->method() === HttpMethod::Post && ($body['action'] ?? null) !== 'save_activity_settings') {
                    throw new InvalidArgumentException('Profile activity settings action is invalid.');
                }
                $view = $body['view_scope'] ?? null;
                $post = $body['post_scope'] ?? null;
                if (!is_string($view) || !is_string($post)) throw new InvalidArgumentException('Profile activity scopes are invalid.');
                $settings = new ProfileActivitySettings(ProfileActivityScope::from($view), ProfileActivityScope::from($post));
                $this->service->updateSettings($actor, $settings);

                if ($request->method() === HttpMethod::Post) {
                    return Response::redirect(
                        $this->basePath->prepend('/account/profile-activity?updated=1'),
                        303,
                    )->withHeader('Cache-Control', 'no-store');
                }
            } else {
                throw new InvalidArgumentException('Unsupported profile activity settings method.');
            }
            return $this->json(['view_scope' => $settings->viewScope->value, 'post_scope' => $settings->postScope->value]);
        } catch (\ValueError|InvalidArgumentException) {
            return $this->wantsHtml($request)
                ? Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store')
                : $this->json(['error' => 'invalid_profile_activity_settings'], 400);
        } catch (ProfileActivityException|\Forwext\Core\Domain\Access\Permission\PermissionDeniedException) {
            return $this->wantsHtml($request)
                ? Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store')
                : $this->json(['error' => 'profile_activity_unavailable'], 409);
        }
    }

    private function wantsHtml(Request $request): bool
    {
        return str_contains(strtolower($request->headers()->line('accept') ?? ''), 'text/html');
    }

    /** @param array<string,mixed> $p */ private function json(array $p, int $s = 200): Response { return Response::json($p, $s)->withHeader('Cache-Control', 'private, no-store'); }
}
