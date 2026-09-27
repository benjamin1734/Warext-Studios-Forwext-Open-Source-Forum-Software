<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Auth\Delivery\AuthLinkDelivery;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Proxy\TrustedProxyResolver;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Registration\RegistrationException;
use Forwext\Core\Registration\RegistrationMode;
use Forwext\Core\Registration\RegistrationPolicy;
use Forwext\Core\Registration\RegistrationRequest;
use Forwext\Core\Registration\RegistrationService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use InvalidArgumentException;

final readonly class RegisterHandler implements RequestHandlerInterface
{
    public function __construct(
        private RegistrationService $registration,
        private RegistrationPolicy $policy,
        private ProfileViewerResolver $viewers,
        private TrustedProxyResolver $proxyResolver,
        private BasePath $basePath,
        private string $defaultLocale,
        private string $defaultTimezone,
        private AuthLinkDelivery $delivery,
        private ?string $turnstileSiteKey = null,
        private string $turnstileAction = 'register',
    ) {
        if ($defaultLocale === '' || $defaultTimezone === '') {
            throw new InvalidArgumentException('Registration locale/timezone defaults are invalid.');
        }
    }

    public function handle(Request $request): Response
    {
        if ($this->viewers->resolve($request) !== null) {
            return $this->redirectHome();
        }
        if ($this->policy->mode === RegistrationMode::Closed) {
            return $this->closed();
        }
        if ($this->policy->emailVerificationRequired && !$this->delivery->available()) {
            return $this->deliveryUnavailable();
        }
        if ($this->policy->captchaRequired && ($this->turnstileSiteKey === null || trim($this->turnstileSiteKey) === '')) {
            return $this->captchaUnavailable();
        }
        if ($request->method() === HttpMethod::Post) {
            return $this->submit($request);
        }
        return $this->view($request);
    }

    private function submit(Request $request): Response
    {
        $body = $request->parsedBody();
        $username = $body['username'] ?? null;
        $email = $body['email'] ?? null;
        $password = $body['password'] ?? null;
        $confirmation = $body['password_confirmation'] ?? null;
        if (!is_string($username) || !is_string($email) || !is_string($password) || !is_string($confirmation)
            || !hash_equals($password, $confirmation)
        ) {
            return $this->view(
                $request,
                true,
                is_string($username) ? $username : '',
                is_string($email) ? $email : '',
            );
        }

        $legal = [];
        foreach ($this->policy->legalDocuments() as $type => $document) {
            $accepted = $body['legal_' . $type] ?? null;
            if (is_string($accepted) && hash_equals($document->version, $accepted)) {
                $legal[$type] = $document->version;
            }
        }

        $captcha = $body['cf-turnstile-response'] ?? null;
        $invite = $body['invite_code'] ?? null;
        $userAgent = trim((string) ($request->headers()->first('User-Agent') ?? ''));
        if ($userAgent === '') {
            $userAgent = 'Forwext Browser';
        }

        try {
            $result = $this->registration->register(new RegistrationRequest(
                trim($username),
                trim($email),
                $this->defaultLocale,
                $this->defaultTimezone,
                $this->proxyResolver->resolve($request)->clientIp,
                is_string($captcha) ? $captcha : null,
                is_string($invite) && trim($invite) !== '' ? trim($invite) : null,
                $legal,
                $password,
                $userAgent,
            ));
        } catch (RegistrationException|InvalidArgumentException) {
            return $this->view($request, true, trim($username), trim($email));
        }

        if ($result->emailVerificationToken !== null
            && !$this->delivery->sendEmailVerification(EmailAddress::fromString($email), $result->emailVerificationToken)
        ) {
            return $this->statusPage(
                'Doğrulama gönderilemedi',
                'Hesabın oluşturuldu ancak doğrulama e-postası gönderilemedi. Yöneticiyle iletişime geç.',
                503,
            );
        }

        $message = match ($result->status) {
            UserStatus::PendingEmailVerification => 'Hesabın oluşturuldu. E-posta doğrulama bağlantısı gönderildi.',
            UserStatus::PendingApproval => 'Hesabın oluşturuldu ve yönetici onayı bekliyor.',
            default => 'Hesabın oluşturuldu. Giriş yapabilirsin.',
        };

        return $this->success($message);
    }

    private function view(Request $request, bool $invalid = false, string $username = '', string $email = ''): Response
    {
        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $fields = '<label><span>Kullanıcı adı</span><input type="text" name="username" autocomplete="username" maxlength="191" required value="'
            . self::e($username) . '"></label>'
            . '<label><span>E-posta</span><input type="email" name="email" autocomplete="email" maxlength="254" required value="'
            . self::e($email) . '"></label>'
            . '<label><span>Parola</span><input type="password" name="password" autocomplete="new-password" maxlength="1024" required></label>'
            . '<label><span>Parolayı tekrar et</span><input type="password" name="password_confirmation" autocomplete="new-password" maxlength="1024" required></label>';

        if ($this->policy->mode->requiresInvite()) {
            $fields .= '<label><span>Davet kodu</span><input type="text" name="invite_code" autocomplete="off" maxlength="128" required></label>';
        }
        foreach ($this->policy->legalDocuments() as $type => $document) {
            $fields .= '<label class="auth-entry-check"><input type="checkbox" name="legal_' . self::e($type) . '" value="'
                . self::e($document->version) . '" required><span>' . self::e($type) . ' belgesinin '
                . self::e($document->version) . ' sürümünü kabul ediyorum.</span></label>';
        }

        $turnstile = '';
        if ($this->policy->captchaRequired && $this->turnstileSiteKey !== null) {
            $turnstile = '<div class="auth-entry-challenge"><div class="cf-turnstile" data-sitekey="'
                . self::e($this->turnstileSiteKey) . '" data-action="' . self::e($this->turnstileAction) . '"></div></div>'
                . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        }

        $html = '<section class="auth-entry"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">HESAP</span><h1>Kayıt ol</h1>'
            . '<p>Forwext topluluğuna katılmak için hesabını oluştur.</p></div>'
            . ($invalid ? '<div class="auth-entry-error" role="alert">Kayıt oluşturulamadı. Bilgileri kontrol edip tekrar dene.</div>' : '')
            . '<form class="auth-entry-form" method="post" action="' . self::e($this->basePath->prepend('/register')) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($token) . '">'
            . $fields . $turnstile
            . '<button class="fx-btn fx-btn--primary auth-entry-submit" type="submit">Hesap oluştur</button></form>'
            . '<div class="auth-entry-links"><span>Zaten hesabın var mı?</span><a href="'
            . self::e($this->basePath->prepend('/login')) . '">Giriş yap</a></div>'
            . '</div></section>';

        return Response::html(ProfileHtml::page(
            'Kayıt ol',
            $html,
            $this->basePath,
            breadcrumbs: new BreadcrumbTrail([
                new BreadcrumbItem('Ana Sayfa', '/'),
                new BreadcrumbItem('Kayıt ol'),
            ]),
        ), $invalid ? 422 : 200)->withHeader('Cache-Control', 'no-store');
    }

    private function success(string $message): Response
    {
        $html = '<section class="auth-entry"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">HESAP</span><h1>Kayıt tamamlandı</h1><p>'
            . self::e($message) . '</p></div><div class="auth-entry-actions"><a class="fx-btn fx-btn--primary" href="'
            . self::e($this->basePath->prepend('/login')) . '">Giriş sayfasına git</a></div></div></section>';
        return Response::html(ProfileHtml::page('Kayıt tamamlandı', $html, $this->basePath))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function closed(): Response
    {
        return $this->statusPage('Kayıt kapalı', 'Yeni kullanıcı kaydı şu anda kapalı.', 403);
    }

    private function captchaUnavailable(): Response
    {
        return $this->statusPage('Kayıt kullanılamıyor', 'Kayıt doğrulama servisi henüz yapılandırılmamış.', 503);
    }

    private function deliveryUnavailable(): Response
    {
        return $this->statusPage(
            'Kayıt kullanılamıyor',
            'E-posta doğrulama teslimi yapılandırılmadan yeni hesap oluşturulamaz.',
            503,
        );
    }

    private function statusPage(string $title, string $message, int $status): Response
    {
        $html = '<section class="auth-entry"><div class="auth-entry-card card"><div class="auth-entry-copy">'
            . '<span class="forum-eyebrow">HESAP</span><h1>' . self::e($title) . '</h1><p>' . self::e($message)
            . '</p></div><div class="auth-entry-actions"><a class="fx-btn" href="'
            . self::e($this->basePath->prepend('/')) . '">Ana sayfaya dön</a></div></div></section>';
        return Response::html(ProfileHtml::page($title, $html, $this->basePath), $status)
            ->withHeader('Cache-Control', 'no-store');
    }

    private function redirectHome(): Response
    {
        return Response::text('', 303)->withHeader('Location', $this->basePath->prepend('/'))
            ->withHeader('Cache-Control', 'no-store');
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
