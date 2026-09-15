<?php

declare(strict_types=1);

namespace Forwext\App\Web\Editor;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class EditorMentionLookupHandler implements RequestHandlerInterface
{
    public function __construct(
        private UserRepository $users,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($this->viewers->resolve($request) === null) {
            return Response::json(['error' => 'authentication_required'], 401)
                ->withHeader('Cache-Control', 'no-store');
        }

        $raw = $request->query()['username'] ?? null;
        if (!is_string($raw)) {
            return Response::json(['error' => 'invalid_username'], 400)
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            $username = Username::fromString($raw);
        } catch (InvalidArgumentException) {
            return Response::json(['error' => 'invalid_username'], 422)
                ->withHeader('Cache-Control', 'no-store');
        }

        $user = $this->users->findByUsername($username);
        if ($user === null) {
            return Response::json(['error' => 'user_not_found'], 404)
                ->withHeader('Cache-Control', 'no-store');
        }

        return Response::json([
            'id' => $user->id()->value(),
            'label' => '@' . $user->username()->display(),
            'url' => $this->basePath->prepend('/members/' . rawurlencode($user->username()->display())),
        ])->withHeader('Cache-Control', 'private, no-store');
    }
}
