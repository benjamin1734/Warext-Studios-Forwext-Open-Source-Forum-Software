<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Auth\OAuth\OAuthConnectedAccountService;
use Forwext\Core\Auth\OAuth\OAuthException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;

final readonly class OAuthStartHandler implements RequestHandlerInterface
{
    /** @param array<string,string> $redirectUris */
    public function __construct(
        private OAuthConnectedAccountService $oauth,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private array $redirectUris,
    ) {
    }

    public function handle(Request $request): Response
    {
        $provider = $this->provider($request);
        $redirectUri = $this->redirectUris[$provider] ?? null;
        if (!is_string($redirectUri) || $redirectUri === '') {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        $actor = $this->viewers->resolve($request);

        try {
            if ($request->method() === HttpMethod::Post) {
                if ($actor === null) {
                    return Response::text('Authentication required.', 401)
                        ->withHeader('Cache-Control', 'no-store');
                }
                $body = $request->parsedBody();
                if (($body['intent'] ?? null) !== 'link') {
                    return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
                }
                $start = $this->oauth->begin($provider, $redirectUri, $actor);
            } else {
                if ($actor !== null) {
                    return Response::redirect($this->basePath->prepend('/account/security'), 303)
                        ->withHeader('Cache-Control', 'no-store');
                }
                $start = $this->oauth->begin($provider, $redirectUri);
            }
        } catch (OAuthException) {
            return Response::text('OAuth provider unavailable.', 404)
                ->withHeader('Cache-Control', 'no-store');
        }

        return Response::redirect($start->authorizationUrl, 302)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    private function provider(Request $request): string
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $provider = is_array($parameters) ? ($parameters['provider'] ?? null) : null;
        return is_string($provider) ? strtolower($provider) : '';
    }
}
