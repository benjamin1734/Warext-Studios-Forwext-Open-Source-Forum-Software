<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Auth\Mfa\Challenge\MfaChallengeStore;
use Forwext\Core\Auth\Mfa\Enrollment\PendingMfaEnrollmentService;
use Forwext\Core\Auth\Mfa\Login\MfaLoginCompletionService;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Http\Cookie\ResponseCookie;
use Forwext\Core\Http\Cookie\SameSite;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class MfaEnrollmentHandler implements RequestHandlerInterface
{
    public function __construct(
        private PendingMfaEnrollmentService $enrollment,
        private MfaLoginCompletionService $completion,
        private MfaChallengeStore $challenges,
        private UserRepository $users,
        private BasePath $basePath,
        private string $sessionCookieName,
        private int $sessionTtlSeconds,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $challenge = $this->challenge($request);
        } catch (InvalidArgumentException) {
            return $this->expired();
        }
        $grant = $this->challenges->inspect($challenge);
        if ($grant === null) {
            return $this->expired();
        }
        $user = $this->users->find($grant->userId);
        if ($user === null) {
            return $this->expired();
        }

        $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($csrf) || $csrf === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        if ($request->method() === HttpMethod::Post) {
            return $this->submit($request, $challenge, $csrf, $user->username()->value());
        }

        try {
            return $this->setup($challenge, $csrf, $user->username()->value());
        } catch (MfaException) {
            return $this->expired();
        }
    }

    private function submit(Request $request, string $challenge, string $csrf, string $username): Response
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }

        try {
            if ($action === 'confirm_totp') {
                $code = $body['code'] ?? null;
                if (!is_string($code)) {
                    throw new MfaException('TOTP code is missing.');
                }
                $recoveryCodes = $this->enrollment->confirmTotp($challenge, trim($code));
            } elseif ($action === 'complete_passkey') {
                $ceremony = $body['ceremony'] ?? null;
                $response = $body['passkey_response'] ?? null;
                $label = $body['label'] ?? null;
                if (!is_string($ceremony) || !is_string($response) || !is_string($label)) {
                    throw new MfaException('Passkey enrollment payload is incomplete.');
                }
                $recoveryCodes = $this->enrollment->completePasskey(
                    $challenge,
                    $ceremony,
                    $response,
                    $label,
                );
            } else {
                return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
            }

            $result = $this->completion->completeEnrollment($challenge);
        } catch (MfaException|InvalidArgumentException) {
            try {
                return $this->setup($challenge, $csrf, $username, true);
            } catch (MfaException) {
                return $this->expired();
            }
        }

        return Response::html(
            ProfileHtml::page(
                'MFA kurulumu tamamlandı',
                $this->recoveryCodes($recoveryCodes),
                $this->basePath,
            ),
        )->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow')
            ->withCookie(new ResponseCookie(
                $this->sessionCookieName,
                $result->login->sessionId,
                maxAge: $this->sessionTtlSeconds,
                path: '/',
                secure: true,
                httpOnly: true,
                sameSite: SameSite::Lax,
            ));
    }

    private function setup(
        string $challenge,
        string $csrf,
        string $username,
        bool $invalid = false,
    ): Response {
        $totp = $this->enrollment->beginTotp($challenge, $username);
        $passkey = $this->enrollment->beginPasskey($challenge, $username, $username);
        $action = self::e($this->basePath->prepend('/mfa/enroll?challenge=' . rawurlencode($challenge)));

        $body = '<section class="auth-entry mfa-enrollment"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">GÜVENLİK</span><h1>MFA kurulumu</h1>'
            . '<p>Hesabında MFA zorunlu. Aşağıdaki yöntemlerden birini kurmadan oturum oluşturulmaz.</p></div>'
            . ($invalid ? '<div class="auth-entry-error" role="alert">Kurulum doğrulanamadı. Bilgileri kontrol edip yeniden dene.</div>' : '')
            . '<section class="mfa-enrollment-method"><h2>Doğrulama uygulaması</h2>'
            . '<p>Authenticator uygulamana bu gizli anahtarı ekle veya otpauth bağlantısını içe aktar.</p>'
            . '<div class="mfa-enrollment-secret"><code>' . self::e($totp->secretBase32) . '</code></div>'
            . '<details><summary>otpauth bağlantısını göster</summary><code class="mfa-enrollment-uri">'
            . self::e($totp->otpauthUri) . '</code></details>'
            . '<form class="auth-entry-form" method="post" action="' . $action . '">'
            . self::hidden('_csrf', $csrf)
            . self::hidden('action', 'confirm_totp')
            . '<label><span>6 haneli kod</span><input type="text" name="code" inputmode="numeric" '
            . 'autocomplete="one-time-code" maxlength="12" required></label>'
            . '<button class="fx-btn fx-btn--primary" type="submit">TOTP kurulumunu doğrula</button></form></section>'
            . '<div class="auth-oauth"><span>veya</span></div>'
            . '<section class="mfa-enrollment-method"><h2>Passkey</h2>'
            . '<p>Cihazındaki biyometrik doğrulama veya güvenlik anahtarını kullan.</p>'
            . '<form class="auth-entry-form" method="post" action="' . $action . '" data-auth-passkey-register-form>'
            . self::hidden('_csrf', $csrf)
            . self::hidden('action', 'complete_passkey')
            . self::hidden('ceremony', $passkey->token)
            . '<label><span>Passkey adı</span><input type="text" name="label" value="Ana cihaz" maxlength="191" required></label>'
            . '<input type="hidden" name="passkey_response" value="" data-auth-passkey-register-response>'
            . '<input type="hidden" value="' . self::e(base64_encode($passkey->optionsJson))
            . '" data-auth-passkey-register-options>'
            . '<div class="auth-entry-notice" data-auth-passkey-register-status hidden></div>'
            . '<button class="fx-btn fx-btn--primary" type="button" data-auth-passkey-register-button>Passkey oluştur</button>'
            . '</form></section>'
            . '<div class="auth-entry-actions"><a class="fx-btn" href="' . self::e($this->basePath->prepend('/login'))
            . '">Girişe dön</a></div></div></section>'
            . '<script src="' . self::e($this->basePath->prepend('/assets/auth-mfa.js')) . '" defer></script>';

        return Response::html(ProfileHtml::page('MFA kurulumu', $body, $this->basePath), $invalid ? 422 : 200)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    /** @param list<string> $codes */
    private function recoveryCodes(array $codes): string
    {
        $items = '';
        foreach ($codes as $code) {
            $items .= '<li><code>' . self::e($code) . '</code></li>';
        }

        return '<section class="auth-entry mfa-enrollment"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">TAMAMLANDI</span><h1>MFA etkinleştirildi</h1>'
            . '<p>Bu kurtarma kodları yalnızca şimdi gösterilir. Güvenli bir yerde sakla.</p></div>'
            . '<ul class="mfa-recovery-codes">' . $items . '</ul>'
            . '<div class="auth-entry-notice"><strong>Önemli</strong><span>Her kurtarma kodu yalnızca bir kez kullanılabilir.</span></div>'
            . '<div class="auth-entry-actions"><a class="fx-btn fx-btn--primary" href="'
            . self::e($this->basePath->prepend('/')) . '">Foruma devam et</a></div></div></section>';
    }

    private function challenge(Request $request): string
    {
        $value = $request->query()['challenge'] ?? null;
        if (!is_string($value) || preg_match('/^mfa_[a-f0-9]{64}$/D', $value) !== 1) {
            throw new InvalidArgumentException('MFA enrollment challenge is invalid.');
        }
        return $value;
    }

    private function expired(): Response
    {
        return Response::html(ProfileHtml::page(
            'MFA kurulumu',
            '<section class="auth-entry"><div class="auth-entry-card card"><div class="auth-entry-copy">'
            . '<h1>Kurulum bağlantısının süresi doldu</h1><p>Giriş işlemini yeniden başlat.</p></div>'
            . '<div class="auth-entry-actions"><a class="fx-btn fx-btn--primary" href="'
            . self::e($this->basePath->prepend('/login')) . '">Girişe dön</a></div></div></section>',
            $this->basePath,
        ), 410)->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
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
