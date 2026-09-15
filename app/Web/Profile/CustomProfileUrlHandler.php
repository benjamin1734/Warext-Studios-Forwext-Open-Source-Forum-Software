<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\Url\ProfileUrlService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;

final readonly class CustomProfileUrlHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileUrlService $urls,
        private UserRepository $users,
        private ProfileService $profiles,
        private ProfileViewerResolver $viewers,
        private ProfileViewHandler $profilesPage,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $slug = is_array($parameters) ? ($parameters['slug'] ?? null) : null;
        if (!is_string($slug)) {
            return Response::text('Not Found', 404);
        }

        $resolution = $this->urls->resolve($slug);
        if ($resolution === null) {
            return Response::text('Not Found', 404);
        }

        $user = $this->users->find($resolution->userId);
        if ($user === null || $user->status() !== UserStatus::Active) {
            return Response::text('Not Found', 404);
        }

        $viewerId = $this->viewers->resolve($request);
        $visible = $this->profiles->visibleProfile(
            $user->id(),
            $viewerId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        if ($visible === null) {
            return Response::text('Not Found', 404);
        }

        if (!$resolution->isCurrent || $slug !== $resolution->requestedSlug->value()) {
            return Response::text('', 308)
                ->withHeader('Location', $this->basePath->prepend('/u/' . rawurlencode($resolution->currentSlug->value())))
                ->withHeader('Cache-Control', 'private, no-store');
        }

        return $this->profilesPage->handle($request->withAttribute(
            Router::ATTRIBUTE_ROUTE_PARAMETERS,
            ['username' => $user->username()->display()],
        ));
    }
}
