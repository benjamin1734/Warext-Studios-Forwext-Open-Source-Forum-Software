<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Auth\AuthenticationRejectedException;
use Forwext\Core\Auth\Login\AuthenticationService;
use Forwext\Core\Auth\Login\LoginRequest;
use Forwext\Core\Auth\Mfa\Login\SecondFactorRequiredException;
use Forwext\Core\Http\Cookie\ResponseCookie;
use Forwext\Core\Http\Cookie\SameSite;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Proxy\TrustedProxyResolver;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use InvalidArgumentException;

final readonly class LoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private AuthenticationService $authentication,
        private ProfileViewerResolver $viewers,
        private TrustedProxyResolver $proxyResolver,
        private BasePath $basePath,
        private string $sessionCookieName,
        private int $sessionTtlSeconds,
    ) {
        if ($sessionCookieName === '' || $sessionTtlSeconds < 300) {
            throw new InvalidArgumentException('Login handler session configuration is invalid.');
        }
    }

    public function handle(Request $request): Response
    {
        if ($this->viewers->resolve($request) !== null) {
            return $this->redirectHome();
        }

        if ($request->method() === HttpMethod::Post) {
            return $this->submit($request);
        }

        return $this->view($request);
    }

    private function submit(Request $request): Response
    {
        $body = $request->parsedBody();
        $identifier = $body['identifier'] ?? null;
        $password = $body['password'] ?? null;

        if (!is_string($identifier) || !is_string($password)) {
            return $this->view($request, true, '');
        }

        $identifier = trim($identifier);
        $userAgent = trim((string) ($request->headers()->first('User-Agent') ?? ''));
        if ($userAgent === '') {
            $userAgent = 'Forwext Browser';
        }

        $previousSessionId = $request->cookie($this->sessionCookieName);
        if ($previousSessionId !== null && preg_match('/^s_[A-Za-z0-9_-]{43}$/D', $previousSessionId) !== 1) {
            $previousSessionId = null;
        }

        try {
            $result = $this->authentication->login(new LoginRequest(
                $identifier,
                $password,
                $this->proxyResolver->resolve($request)->clientIp,
                $userAgent,
                previousSessionId: $previousSessionId,
            ));
        } catch (SecondFactorRequiredException) {
            return $this->view(
                $request,
                false,
                $identifier,
                true,
            );
        } catch (AuthenticationRejectedException|InvalidArgumentException) {
            return $this->view($request, true, $identifier);
        }

        return $this->redirectHome()->withCookie(new ResponseCookie(
            $this->sessionCookieName,
            $result->sessionId,
            maxAge: $this->sessionTtlSeconds,
            path: '/',
            secure: true,
            httpOnly: true,
            sameSite: SameSite::Lax,
        ));
    }

    private function view(
        Request $request,
        bool $invalidCredentials = false,
        string $identifier = '',
        bool $mfaRequired = false,
    ): Response {
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $action = self::e($this->basePath->prepend('/login'));
        $html = '<section class="auth-entry"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">HESAP</span><h1>Giriş yap</h1>'
            . '<p>Forwext hesabınla forumdaki kişisel ve etkileşimli alanlara eriş.</p></div>'
            . ($invalidCredentials
                ? '<div class="auth-entry-error" role="alert">Kullanıcı adı/e-posta veya parola doğrulanamadı.</div>'
                : '')
            . ($mfaRequired
                ? '<div class="auth-entry-notice" role="status"><strong>Ek doğrulama gerekli.</strong>'
                    . '<span>Hesabın için çok faktörlü doğrulama zorunlu. Güvenlik nedeniyle oturum oluşturulmadı; '
                    . 'MFA doğrulama ekranı sonraki native hesap geçişinde bağlanacak.</span></div>'
                : '')
            . '<form class="auth-entry-form" method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($token) . '">'
            . '<label><span>Kullanıcı adı veya e-posta</span><input type="text" name="identifier" '
            . 'autocomplete="username" maxlength="512" required value="' . self::e($identifier) . '"></label>'
            . '<label><span>Parola</span><input type="password" name="password" autocomplete="current-password" '
            . 'maxlength="1024" required></label>'
            . '<button class="fx-btn fx-btn--primary auth-entry-submit" type="submit">Giriş yap</button>'
            . '</form></div></section>';

        return Response::html(ProfileHtml::page(
            'Giriş yap',
            $html,
            $this->basePath,
            breadcrumbs: new BreadcrumbTrail([
                new BreadcrumbItem('Ana Sayfa', '/'),
                new BreadcrumbItem('Giriş yap'),
            ]),
        ), $invalidCredentials ? 422 : ($mfaRequired ? 409 : 200))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function redirectHome(): Response
    {
        return Response::text('', 303)
            ->withHeader('Location', $this->basePath->prepend('/'))
            ->withHeader('Cache-Control', 'no-store');
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
