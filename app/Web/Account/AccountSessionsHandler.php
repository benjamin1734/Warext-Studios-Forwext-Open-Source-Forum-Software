<?php

declare(strict_types=1);

namespace Forwext\App\Web\Account;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Auth\Session\AuthSessionIndex;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class AccountSessionsHandler implements RequestHandlerInterface
{
    public function __construct(
        private AuthSessionIndex $sessions,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private string $cookieName,
        private DateTimeZone $timezone,
    ) {
        if (preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $cookieName) !== 1) {
            throw new InvalidArgumentException('Authentication session cookie name is invalid.');
        }
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        $sessionId = $request->cookie($this->cookieName);
        if (!is_string($sessionId) || preg_match('/^s_[A-Za-z0-9_-]{43}$/D', $sessionId) !== 1) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }
        $currentHash = hash('sha256', $sessionId);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($request->method() === HttpMethod::Post) {
            $body = $request->parsedBody();
            $action = $body['action'] ?? null;
            if ($action === 'revoke_others') {
                $this->sessions->revokeOthers($actor, $currentHash, $now);
            } elseif ($action === 'revoke_session') {
                $hash = $body['session_hash'] ?? null;
                if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 || hash_equals($currentHash, $hash)) {
                    return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
                }
                $this->sessions->revokeForUser($actor, $hash, $now);
            } else {
                return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
            }

            return Response::redirect($this->basePath->prepend('/account/sessions?updated=1'), 303)
                ->withHeader('Cache-Control', 'no-store');
        }

        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        return Response::html(AccountSessionsHtml::page(
            $this->sessions->activeForUser($actor, $now),
            $currentHash,
            $token,
            $this->basePath,
            $this->timezone,
            ($request->query()['updated'] ?? null) === '1',
        ))->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }
}
