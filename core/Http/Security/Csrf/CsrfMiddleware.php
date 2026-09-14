<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\Csrf;

use Forwext\Core\Http\Cookie\ResponseCookie;
use Forwext\Core\Http\Cookie\SameSite;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;

final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE_CONTEXT = 'csrf_context_id';
    public const ATTRIBUTE_TOKEN = 'csrf_token';

    public function __construct(
        private CsrfTokenManager $tokens,
        private string $scope = 'web',
        private string $cookieName = '__Host-forwext_csrf',
        private bool $secureCookie = true,
        private int $cookieMaxAge = 7200,
    ) {
        if ($cookieMaxAge < 60) {
            throw new CsrfException('CSRF cookie lifetime must be at least 60 seconds.');
        }
        if ($cookieName === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $cookieName) !== 1) {
            throw new CsrfException('CSRF cookie name is invalid.');
        }
        if (str_starts_with($cookieName, '__Host-') && !$secureCookie) {
            throw new CsrfException('__Host- CSRF cookies require Secure transport.');
        }
    }

    public function process(Request $request, RequestHandlerInterface $next): Response
    {
        $cookieContext = $request->cookie($this->cookieName);
        $context = is_string($cookieContext) && preg_match('/^[a-f0-9]{64}$/D', $cookieContext) === 1
            ? $cookieContext
            : null;

        if ($request->method()->isSafe()) {
            $newContext = $context === null;
            if ($context === null) {
                $context = bin2hex(random_bytes(32));
            }

            $token = $this->tokens->issue($context, $this->scope);
            $response = $next->handle(
                $request
                    ->withAttribute(self::ATTRIBUTE_CONTEXT, $context)
                    ->withAttribute(self::ATTRIBUTE_TOKEN, $token),
            );

            if (!$newContext) {
                return $response;
            }

            return $response->withCookie(new ResponseCookie(
                $this->cookieName,
                $context,
                maxAge: $this->cookieMaxAge,
                path: '/',
                secure: $this->secureCookie,
                httpOnly: true,
                sameSite: SameSite::Lax,
            ));
        }

        if ($context === null) {
            return $this->rejected();
        }

        $token = $request->headers()->first('X-CSRF-Token');
        if ($token === null) {
            $formToken = $request->parsedBody()['_csrf'] ?? null;
            $token = is_string($formToken) ? $formToken : null;
        }

        if ($token === null || !$this->tokens->verify($token, $context, $this->scope)) {
            return $this->rejected();
        }

        return $next->handle($request->withAttribute(self::ATTRIBUTE_CONTEXT, $context));
    }

    private function rejected(): Response
    {
        return Response::text('CSRF validation failed.', 403)
            ->withHeader('Cache-Control', 'no-store');
    }
}
