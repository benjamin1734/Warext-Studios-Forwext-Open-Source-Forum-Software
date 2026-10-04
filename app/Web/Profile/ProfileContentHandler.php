<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\Content\UserForumContentReader;
use Forwext\Core\Profile\Content\UserForumContentType;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class ProfileContentHandler implements RequestHandlerInterface
{
    public function __construct(
        private UserRepository $users,
        private ProfileService $profiles,
        private ProfileViewerResolver $viewers,
        private UserForumContentReader $content,
        private BasePath $basePath,
        private DateTimeZone $timezone,
    ) {
    }

    public function handle(Request $request): Response
    {
        $viewerId = $this->viewers->resolve($request);
        if ($viewerId === null) {
            return Response::html(ProfileContentHtml::authenticationRequired($this->basePath), 401)
                ->withHeader('Cache-Control', 'private, no-store');
        }

        [$username, $type] = $this->route($request);
        if ($username === null || $type === null) {
            return Response::text('Not Found', 404);
        }

        $user = $this->users->findByUsername($username);
        if ($user === null || $user->status() !== UserStatus::Active) {
            return Response::text('Not Found', 404);
        }

        $visible = $this->profiles->visibleProfile(
            $user->id(),
            $viewerId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        if ($visible === null) {
            return Response::text('Not Found', 404);
        }

        $page = self::page($request);
        $pageSize = 20;
        $items = $type === UserForumContentType::Thread
            ? $this->content->threads($viewerId, $user->id(), $pageSize + 1, ($page - 1) * $pageSize)
            : $this->content->posts($viewerId, $user->id(), $pageSize + 1, ($page - 1) * $pageSize);
        $hasMore = count($items) > $pageSize;
        if ($hasMore) {
            array_pop($items);
        }

        return Response::html(ProfileContentHtml::page(
            $user->username()->display(),
            $items,
            $type,
            $page,
            $hasMore,
            $this->basePath,
            $this->timezone,
        ))->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    /** @return array{0:?Username,1:?UserForumContentType} */
    private function route(Request $request): array
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $usernameRaw = is_array($parameters) ? ($parameters['username'] ?? null) : null;
        $kindRaw = is_array($parameters) ? ($parameters['kind'] ?? null) : null;
        if (!is_string($usernameRaw) || !is_string($kindRaw)) {
            return [null, null];
        }

        try {
            $username = Username::fromString($usernameRaw);
        } catch (InvalidArgumentException) {
            return [null, null];
        }

        $type = match ($kindRaw) {
            'threads' => UserForumContentType::Thread,
            'posts' => UserForumContentType::Post,
            default => null,
        };

        return [$username, $type];
    }

    private static function page(Request $request): int
    {
        $raw = $request->query()['page'] ?? null;
        if ($raw === null || $raw === '') {
            return 1;
        }
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,2}$/D', $raw) !== 1) {
            throw new InvalidArgumentException('Profile content page is invalid.');
        }
        $page = (int) $raw;
        if ($page > 500) {
            throw new InvalidArgumentException('Profile content page is outside the supported range.');
        }

        return $page;
    }
}
