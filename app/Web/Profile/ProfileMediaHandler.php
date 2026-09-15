<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\ProfileMediaKind;
use Forwext\Core\Profile\ProfileMediaService;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class ProfileMediaHandler implements RequestHandlerInterface
{
    public function __construct(
        private UserRepository $users,
        private ProfileMediaService $media,
        private ProfileViewerResolver $viewers,
        private ProfileMediaKind $kind,
    ) {
    }

    public function handle(Request $request): Response
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['username'] ?? null) : null;
        if (!is_string($value)) {
            return Response::text('Not Found', 404);
        }

        try {
            $username = Username::fromString($value);
        } catch (InvalidArgumentException) {
            return Response::text('Not Found', 404);
        }

        $user = $this->users->findByUsername($username);
        if ($user === null || $user->status() !== UserStatus::Active) {
            return Response::text('Not Found', 404);
        }

        $media = $this->media->read($user->id(), $this->viewers->resolve($request), $this->kind);
        if ($media === null) {
            return Response::text('Not Found', 404);
        }

        return new Response(
            $media->contents,
            200,
            new HeaderBag([
                'Content-Type' => $media->contentType,
                'Content-Length' => (string) strlen($media->contents),
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]),
        );
    }
}
