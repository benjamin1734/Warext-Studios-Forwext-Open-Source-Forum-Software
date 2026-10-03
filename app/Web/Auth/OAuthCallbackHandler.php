<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Auth\AuthenticationFingerprint;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Auth\Device\DeviceRepository;
use Forwext\Core\Auth\Login\LoginHistoryRecorder;
use Forwext\Core\Auth\Login\LoginOutcome;
use Forwext\Core\Auth\Mfa\Login\MfaLoginCompletionService;
use Forwext\Core\Auth\Mfa\Login\MfaLoginGate;
use Forwext\Core\Auth\Mfa\Login\SecondFactorRequiredException;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Auth\Mfa\MfaMethod;
use Forwext\Core\Auth\OAuth\OAuthConnectedAccountService;
use Forwext\Core\Auth\OAuth\OAuthException;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Domain\User\UserAuthenticationAvailability;
use Forwext\Core\Http\Cookie\ResponseCookie;
use Forwext\Core\Http\Cookie\SameSite;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Proxy\TrustedProxyResolver;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class OAuthCallbackHandler implements RequestHandlerInterface
{
    /** @param array<string,string> $redirectUris */
    public function __construct(
        private OAuthConnectedAccountService $oauth,
        private CredentialStore $credentials,
        private UserAuthenticationAvailability $availability,
        private AuthenticationFingerprint $fingerprints,
        private DeviceRepository $devices,
        private MfaLoginGate $mfaGate,
        private MfaLoginCompletionService $mfaCompletion,
        private LoginHistoryRecorder $history,
        private AuthSessionManager $sessions,
        private ProfileViewerResolver $viewers,
        private TrustedProxyResolver $proxyResolver,
        private BasePath $basePath,
        private string $sessionCookieName,
        private int $sessionTtlSeconds,
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

        if ($request->method() === HttpMethod::Post) {
            return $this->completeMfa($request);
        }

        $state = $request->query()['state'] ?? null;
        $code = $request->query()['code'] ?? null;
        if (!is_string($state) || !is_string($code) || $state === '' || $code === '') {
            return $this->oauthError('OAuth sağlayıcısından geçerli bir doğrulama yanıtı alınamadı.');
        }

        $actor = $this->viewers->resolve($request);
        try {
            $user = $this->oauth->complete($provider, $state, $code, $redirectUri, $actor);
        } catch (OAuthException|InvalidArgumentException) {
            return $this->oauthError('OAuth doğrulaması tamamlanamadı veya bağlantı isteğinin süresi doldu.');
        }

        if ($actor !== null) {
            return Response::redirect($this->basePath->prepend('/account/security?linked=1'), 303)
                ->withHeader('Cache-Control', 'no-store');
        }

        if (!$this->availability->allows($user->id())) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        }

        $credential = $this->credentials->find($user->id());
        if ($credential === null) {
            return $this->oauthError('Bu hesabın oturum açma kimliği tamamlanmamış.');
        }

        $userAgent = trim((string) ($request->headers()->first('User-Agent') ?? ''));
        if ($userAgent === '') {
            $userAgent = 'Forwext Browser';
        }
        $clientIp = $this->proxyResolver->resolve($request)->clientIp;

        try {
            $identityFingerprint = $this->fingerprints->identity('oauth:' . $provider . ':' . $user->id()->value());
            $ipFingerprint = $this->fingerprints->ip($clientIp);
            $deviceFingerprint = $this->fingerprints->userAgent($userAgent);
            $device = $this->devices->touch(
                $user->id(),
                null,
                $deviceFingerprint,
                $ipFingerprint,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );

            try {
                $this->mfaGate->enforce(
                    $user->id(),
                    $device->deviceId,
                    $credential->version,
                    false,
                    null,
                    $identityFingerprint,
                    $ipFingerprint,
                    $deviceFingerprint,
                );
            } catch (SecondFactorRequiredException $exception) {
                $this->history->record(
                    $user->id(),
                    $identityFingerprint,
                    $ipFingerprint,
                    $deviceFingerprint,
                    LoginOutcome::MfaRequired,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                );
                return $this->mfaView(
                    $request,
                    $exception->challengeToken,
                    $exception->methods,
                    $exception->enrollmentRequired,
                );
            }

            $previous = $this->validSessionCookie($request);
            $sessionId = $this->sessions->establish(
                $user->id(),
                $device->deviceId,
                $credential->version,
                $previous,
            );
            try {
                $this->history->record(
                    $user->id(),
                    $identityFingerprint,
                    $ipFingerprint,
                    $deviceFingerprint,
                    LoginOutcome::Success,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                );
            } catch (\Throwable $exception) {
                $this->sessions->revoke($sessionId);
                throw $exception;
            }

            return $this->signedIn($sessionId);
        } catch (AuthException|InvalidArgumentException) {
            return $this->oauthError('OAuth oturumu güvenli biçimde oluşturulamadı.');
        }
    }

    private function completeMfa(Request $request): Response
    {
        $body = $request->parsedBody();
        $challenge = $body['mfa_challenge'] ?? null;
        $methodValue = $body['mfa_method'] ?? null;
        $available = $this->parseAvailableMethods(is_string($body['mfa_available'] ?? null)
            ? (string) $body['mfa_available']
            : '');

        if (!is_string($challenge)
            || !is_string($methodValue)
            || ($method = MfaMethod::tryFrom($methodValue)) === null
        ) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }

        try {
            if ($method === MfaMethod::Passkey) {
                $ceremony = $body['mfa_ceremony'] ?? null;
                $response = $body['mfa_passkey_response'] ?? null;
                if (!is_string($ceremony) || !is_string($response) || trim($response) === '') {
                    throw new MfaException('Passkey response is missing.');
                }
                $result = $this->mfaCompletion->completePasskey($challenge, $ceremony, $response);
            } else {
                $code = $body['mfa_code'] ?? null;
                if (!is_string($code)) {
                    throw new MfaException('MFA code is missing.');
                }
                $result = $this->mfaCompletion->completeCode($challenge, $method, trim($code));
            }
        } catch (MfaException|InvalidArgumentException) {
            return $this->mfaView($request, $challenge, $available, false, true);
        }

        return $this->signedIn($result->login->sessionId);
    }

    /** @param list<MfaMethod> $methods */
    private function mfaView(
        Request $request,
        string $challenge,
        array $methods,
        bool $enrollmentRequired,
        bool $invalid = false,
    ): Response {
        $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($csrf) || $csrf === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $available = implode(',', array_map(
            static fn (MfaMethod $method): string => $method->value,
            array_values(array_filter($methods, static fn (mixed $m): bool => $m instanceof MfaMethod)),
        ));
        $forms = '';

        if (!$enrollmentRequired && in_array(MfaMethod::Totp, $methods, true)) {
            $forms .= $this->codeForm($request, $csrf, $challenge, $available, MfaMethod::Totp, 'Doğrulama uygulaması', 12);
        }
        if (!$enrollmentRequired && in_array(MfaMethod::RecoveryCode, $methods, true)) {
            $forms .= $this->codeForm($request, $csrf, $challenge, $available, MfaMethod::RecoveryCode, 'Kurtarma kodu', 64);
        }

        if (!$enrollmentRequired && in_array(MfaMethod::Passkey, $methods, true)) {
            try {
                $ceremony = $this->mfaCompletion->beginPasskey($challenge);
                $forms .= '<form class="auth-entry-form auth-entry-mfa-form" method="post" action="'
                    . self::e($request->uri()) . '" data-auth-passkey-form>'
                    . '<div class="auth-entry-copy"><strong>Passkey</strong><p>Cihazındaki passkey ile doğrula.</p></div>'
                    . self::hidden('_csrf', $csrf)
                    . self::hidden('mfa_challenge', $challenge)
                    . self::hidden('mfa_available', $available)
                    . self::hidden('mfa_method', MfaMethod::Passkey->value)
                    . self::hidden('mfa_ceremony', $ceremony->token)
                    . '<input type="hidden" name="mfa_passkey_response" value="" data-auth-passkey-response>'
                    . '<input type="hidden" value="' . self::e(base64_encode($ceremony->optionsJson))
                    . '" data-auth-passkey-options>'
                    . '<div class="auth-entry-notice" data-auth-passkey-status hidden></div>'
                    . '<button class="fx-btn fx-btn--primary" type="button" data-auth-passkey-button>Passkey ile doğrula</button>'
                    . '</form><script src="' . self::e($this->basePath->prepend('/assets/auth-mfa.js')) . '" defer></script>';
            } catch (MfaException) {
                $forms .= '<div class="auth-entry-notice">Passkey doğrulaması şu anda başlatılamıyor.</div>';
            }
        }

        if ($enrollmentRequired) {
            $forms = '<div class="auth-entry-notice" role="alert"><strong>MFA kurulumu gerekli.</strong>'
                . '<span>Bu hesap için MFA zorunlu ancak etkin bir yöntem yok. Güvenlik nedeniyle oturum açılmadı.</span></div>';
        }

        $body = '<section class="auth-entry"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">OAUTH + MFA</span><h1>Ek doğrulama</h1>'
            . '<p>Harici sağlayıcı doğrulandı. Oturumu tamamlamak için ikinci faktörü doğrula.</p></div>'
            . ($invalid ? '<div class="auth-entry-error" role="alert">Doğrulama başarısız veya süresi dolmuş.</div>' : '')
            . $forms . '<div class="auth-entry-actions"><a class="fx-btn" href="'
            . self::e($this->basePath->prepend('/login')) . '">Girişe dön</a></div></div></section>';

        return Response::html(ProfileHtml::page('OAuth ek doğrulama', $body, $this->basePath))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    private function codeForm(
        Request $request,
        string $csrf,
        string $challenge,
        string $available,
        MfaMethod $method,
        string $label,
        int $maxLength,
    ): string {
        return '<form class="auth-entry-form auth-entry-mfa-form" method="post" action="' . self::e($request->uri()) . '">'
            . '<div class="auth-entry-copy"><strong>' . self::e($label) . '</strong></div>'
            . self::hidden('_csrf', $csrf)
            . self::hidden('mfa_challenge', $challenge)
            . self::hidden('mfa_available', $available)
            . self::hidden('mfa_method', $method->value)
            . '<label><span>Kod</span><input type="text" name="mfa_code" maxlength="' . $maxLength
            . '" autocomplete="one-time-code" required></label>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Doğrula ve giriş yap</button></form>';
    }

    private function signedIn(string $sessionId): Response
    {
        return Response::redirect($this->basePath->prepend('/'), 303)
            ->withHeader('Cache-Control', 'no-store')
            ->withCookie(new ResponseCookie(
                $this->sessionCookieName,
                $sessionId,
                maxAge: $this->sessionTtlSeconds,
                path: '/',
                secure: true,
                httpOnly: true,
                sameSite: SameSite::Lax,
            ));
    }

    private function oauthError(string $message): Response
    {
        $body = '<section class="auth-entry"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">OAUTH</span><h1>Giriş tamamlanamadı</h1><p>'
            . self::e($message) . '</p></div><div class="auth-entry-actions"><a class="fx-btn fx-btn--primary" href="'
            . self::e($this->basePath->prepend('/login')) . '">Girişe dön</a></div></div></section>';

        return Response::html(ProfileHtml::page('OAuth hatası', $body, $this->basePath), 422)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
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

    private function validSessionCookie(Request $request): ?string
    {
        $value = $request->cookie($this->sessionCookieName);
        return is_string($value) && preg_match('/^s_[A-Za-z0-9_-]{43}$/D', $value) === 1 ? $value : null;
    }

    private function provider(Request $request): string
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $provider = is_array($parameters) ? ($parameters['provider'] ?? null) : null;
        return is_string($provider) ? strtolower($provider) : '';
    }

    private static function hidden(string $name, string $value): string
    {
        return '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
