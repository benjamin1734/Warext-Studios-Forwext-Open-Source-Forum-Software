<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Auth\OAuth\ConnectedAccountStore;
use Forwext\Core\Auth\OAuth\OAuthConnectedAccountService;
use Forwext\Core\Auth\OAuth\OAuthException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;

final readonly class AccountSecurityHandler implements RequestHandlerInterface
{
    /** @param array<string,bool> $providerEnabled */
    public function __construct(
        private OAuthConnectedAccountService $oauth,
        private ConnectedAccountStore $accounts,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private array $providerEnabled,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            if ($request->method() === HttpMethod::Post) {
                $body = $request->parsedBody();
                if (($body['action'] ?? null) !== 'unlink') {
                    return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
                }
                $provider = $body['provider'] ?? null;
                if (!is_string($provider) || !in_array($provider, ['google','discord'], true)) {
                    return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
                }
                $this->oauth->unlink($actor, $provider);
                return Response::redirect($this->basePath->prepend('/account/security?unlinked=1'), 303)
                    ->withHeader('Cache-Control', 'no-store');
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            return Response::html(AccountSecurityHtml::page(
                $this->accounts->forUser($actor),
                $this->providerEnabled,
                $token,
                $this->basePath,
                ($request->query()['linked'] ?? null) === '1',
                ($request->query()['unlinked'] ?? null) === '1',
            ))->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (OAuthException) {
            return Response::text('Conflict', 409)->withHeader('Cache-Control', 'no-store');
        }
    }
}
