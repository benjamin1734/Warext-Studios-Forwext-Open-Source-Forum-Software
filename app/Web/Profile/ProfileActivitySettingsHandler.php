<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\Activity\ProfileActivityException;
use Forwext\Core\Profile\Activity\ProfileActivityScope;
use Forwext\Core\Profile\Activity\ProfileActivityService;
use Forwext\Core\Profile\Activity\ProfileActivitySettings;
use InvalidArgumentException;

final readonly class ProfileActivitySettingsHandler implements RequestHandlerInterface
{
    public function __construct(private ProfileActivityService $service, private ProfileViewerResolver $viewers) {}

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);
        try {
            if ($request->method() === HttpMethod::Get) {
                $settings = $this->service->settings($actor, $actor);
            } elseif ($request->method() === HttpMethod::Put) {
                $view = $request->parsedBody()['view_scope'] ?? null;
                $post = $request->parsedBody()['post_scope'] ?? null;
                if (!is_string($view) || !is_string($post)) throw new InvalidArgumentException('Profile activity scopes are invalid.');
                $settings = new ProfileActivitySettings(ProfileActivityScope::from($view), ProfileActivityScope::from($post));
                $this->service->updateSettings($actor, $settings);
            } else {
                throw new InvalidArgumentException('Unsupported profile activity settings method.');
            }
            return $this->json(['view_scope' => $settings->viewScope->value, 'post_scope' => $settings->postScope->value]);
        } catch (\ValueError|InvalidArgumentException) {
            return $this->json(['error' => 'invalid_profile_activity_settings'], 400);
        } catch (ProfileActivityException|\Forwext\Core\Domain\Access\Permission\PermissionDeniedException) {
            return $this->json(['error' => 'profile_activity_unavailable'], 409);
        }
    }

    /** @param array<string,mixed> $p */ private function json(array $p, int $s = 200): Response { return Response::json($p, $s)->withHeader('Cache-Control', 'private, no-store'); }
}
