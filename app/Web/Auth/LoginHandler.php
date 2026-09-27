<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Auth\AuthenticationRejectedException;
use Forwext\Core\Auth\Login\AuthenticationService;
use Forwext\Core\Auth\Login\LoginRequest;
use Forwext\Core\Auth\Mfa\Login\MfaLoginCompletionService;
use Forwext\Core\Auth\Mfa\Login\SecondFactorRequiredException;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Auth\Mfa\MfaMethod;
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
        private ?MfaLoginCompletionService $mfaCompletion = null,
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
        $challengeToken = $body['mfa_challenge'] ?? null;
        if (is_string($challengeToken) && $challengeToken !== '') {
            return $this->submitMfa($request, $challengeToken);
        }

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
        } catch (SecondFactorRequiredException $exception) {
            return $this->viewMfa(
                $request,
                $exception->challengeToken,
                $exception->methods,
                $exception->enrollmentRequired,
            );
        } catch (AuthenticationRejectedException|InvalidArgumentException) {
            return $this->view($request, true, $identifier);
        }

        return $this->redirectWithSession($result->sessionId);
    }

    private function submitMfa(Request $request, string $challengeToken): Response
    {
        $body = $request->parsedBody();
        $methodValue = $body['mfa_method'] ?? null;
        $code = $body['mfa_code'] ?? null;
        $availableValue = $body['mfa_available'] ?? '';

        $available = is_string($availableValue)
            ? $this->parseAvailableMethods($availableValue)
            : [];

        if ($this->mfaCompletion === null
            || !is_string($methodValue)
            || !is_string($code)
            || ($method = MfaMethod::tryFrom($methodValue)) === null
            || $method === MfaMethod::Passkey
        ) {
            return $this->viewMfa($request, $challengeToken, $available, false, true);
        }

        try {
            $result = $this->mfaCompletion->completeCode(
                $challengeToken,
                $method,
                trim($code),
            );
        } catch (MfaException|InvalidArgumentException) {
            return $this->viewMfa($request, $challengeToken, $available, false, true);
        }

        return $this->redirectWithSession($result->login->sessionId);
    }

    private function view(
        Request $request,
        bool $invalidCredentials = false,
        string $identifier = '',
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
        ), $invalidCredentials ? 422 : 200)
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param list<MfaMethod> $methods */
    private function viewMfa(
        Request $request,
        string $challengeToken,
        array $methods,
        bool $enrollmentRequired,
        bool $invalidCode = false,
    ): Response {
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $methods = array_values(array_filter(
            $methods,
            static fn (mixed $method): bool => $method instanceof MfaMethod,
        ));
        $available = implode(',', array_map(
            static fn (MfaMethod $method): string => $method->value,
            $methods,
        ));
        $action = self::e($this->basePath->prepend('/login'));
        $forms = '';

        if (in_array(MfaMethod::Totp, $methods, true)) {
            $forms .= $this->mfaCodeForm(
                $action,
                $token,
                $challengeToken,
                $available,
                MfaMethod::Totp,
                'Doğrulama uygulaması',
                '6 haneli doğrulama kodunu gir.',
                'one-time-code',
                12,
            );
        }
        if (in_array(MfaMethod::RecoveryCode, $methods, true)) {
            $forms .= $this->mfaCodeForm(
                $action,
                $token,
                $challengeToken,
                $available,
                MfaMethod::RecoveryCode,
                'Kurtarma kodu',
                'Tek kullanımlık kurtarma kodlarından birini kullan.',
                'off',
                64,
            );
        }

        $passkeyNotice = in_array(MfaMethod::Passkey, $methods, true)
            ? '<div class="auth-entry-notice" role="status"><strong>Passkey hesabında etkin.</strong>'
                . '<span>Passkey tarayıcı doğrulama akışı henüz native giriş ekranına bağlanmadı. '
                . 'TOTP veya kurtarma kodun varsa bunlardan birini kullanabilirsin.</span></div>'
            : '';

        if ($enrollmentRequired) {
            $forms = '<div class="auth-entry-notice" role="alert"><strong>MFA kurulumu gerekli.</strong>'
                . '<span>Bu hesapta çok faktörlü doğrulama zorunlu ancak henüz etkin bir doğrulama yöntemi yok. '
                . 'Güvenlik gereği oturum oluşturulmadı.</span></div>';
        } elseif ($this->mfaCompletion === null || $forms === '') {
            $forms .= '<div class="auth-entry-notice" role="alert"><strong>Native doğrulama yöntemi kullanılamıyor.</strong>'
                . '<span>Oturum güvenlik gereği oluşturulmadı.</span></div>';
        }

        $html = '<section class="auth-entry"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">GÜVENLİK</span><h1>Ek doğrulama</h1>'
            . '<p>Birinci adım doğrulandı. Oturum açmayı tamamlamak için etkin MFA yöntemlerinden birini kullan.</p></div>'
            . ($invalidCode
                ? '<div class="auth-entry-error" role="alert">Doğrulama kodu geçersiz, kullanılmış veya süresi dolmuş olabilir.</div>'
                : '')
            . $passkeyNotice
            . $forms
            . '<div class="auth-entry-actions"><a class="fx-btn" href="'
            . self::e($this->basePath->prepend('/login')) . '">Girişe dön</a></div>'
            . '</div></section>';

        return Response::html(ProfileHtml::page(
            'Ek doğrulama',
            $html,
            $this->basePath,
            breadcrumbs: new BreadcrumbTrail([
                new BreadcrumbItem('Ana Sayfa', '/'),
                new BreadcrumbItem('Giriş yap', '/login'),
                new BreadcrumbItem('Ek doğrulama'),
            ]),
        ), $invalidCode ? 422 : ($enrollmentRequired ? 409 : 200))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function mfaCodeForm(
        string $action,
        string $csrfToken,
        string $challengeToken,
        string $available,
        MfaMethod $method,
        string $title,
        string $description,
        string $autocomplete,
        int $maxlength,
    ): string {
        return '<form class="auth-entry-form auth-entry-mfa-form" method="post" action="' . $action . '">'
            . '<div class="auth-entry-copy"><strong>' . self::e($title) . '</strong><p>'
            . self::e($description) . '</p></div>'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrfToken) . '">'
            . '<input type="hidden" name="mfa_challenge" value="' . self::e($challengeToken) . '">'
            . '<input type="hidden" name="mfa_available" value="' . self::e($available) . '">'
            . '<input type="hidden" name="mfa_method" value="' . self::e($method->value) . '">'
            . '<label><span>Kod</span><input type="text" name="mfa_code" inputmode="numeric" autocomplete="'
            . self::e($autocomplete) . '" maxlength="' . $maxlength . '" required></label>'
            . '<button class="fx-btn fx-btn--primary auth-entry-submit" type="submit">Doğrula ve giriş yap</button>'
            . '</form>';
    }

    /** @return list<MfaMethod> */
    private function parseAvailableMethods(string $value): array
    {
        $methods = [];
        foreach (explode(',', $value) as $part) {
            $method = MfaMethod::tryFrom(trim($part));
            if ($method !== null && !in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }
        return $methods;
    }

    private function redirectWithSession(string $sessionId): Response
    {
        return $this->redirectHome()->withCookie(new ResponseCookie(
            $this->sessionCookieName,
            $sessionId,
            maxAge: $this->sessionTtlSeconds,
            path: '/',
            secure: true,
            httpOnly: true,
            sameSite: SameSite::Lax,
        ));
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
