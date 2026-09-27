<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Auth\Delivery\AuthLinkDelivery;
use Forwext\Core\Auth\Password\PasswordResetService;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use InvalidArgumentException;

final readonly class PasswordResetRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private UserRepository $users,
        private PasswordResetService $passwordReset,
        private AuthLinkDelivery $delivery,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($this->viewers->resolve($request) !== null) {
            return $this->redirectHome();
        }
        if (!$this->delivery->available()) {
            return $this->unavailable();
        }
        if ($request->method() === HttpMethod::Post) {
            return $this->submit($request);
        }
        return $this->view($request);
    }

    private function submit(Request $request): Response
    {
        $identifier = $request->parsedBody()['identifier'] ?? null;
        if (is_string($identifier)) {
            $identifier = trim($identifier);
            try {
                $user = str_contains($identifier, '@')
                    ? $this->users->findByEmail(EmailAddress::fromString($identifier))
                    : $this->users->findByUsername(Username::fromString($identifier));
                if ($user !== null) {
                    $token = $this->passwordReset->issueForUser($user->id());
                    $this->delivery->sendPasswordReset($user->email(), $token);
                }
            } catch (AuthException|InvalidArgumentException) {
                // Public recovery is deliberately enumeration-resistant.
            }
        }

        return $this->sent();
    }

    private function view(Request $request): Response
    {
        $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($csrf) || $csrf === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $html = '<section class="auth-entry"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">HESAP</span><h1>Parolanı sıfırla</h1>'
            . '<p>Kullanıcı adını veya e-posta adresini yaz. Hesap uygunsa sıfırlama bağlantısı gönderilir.</p></div>'
            . '<form class="auth-entry-form" method="post" action="' . self::e($this->basePath->prepend('/forgot-password')) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<label><span>Kullanıcı adı veya e-posta</span><input type="text" name="identifier" autocomplete="username" maxlength="512" required></label>'
            . '<button class="fx-btn fx-btn--primary auth-entry-submit" type="submit">Sıfırlama bağlantısı gönder</button></form>'
            . '<div class="auth-entry-links"><a href="' . self::e($this->basePath->prepend('/login')) . '">Girişe dön</a></div>'
            . '</div></section>';

        return Response::html(ProfileHtml::page(
            'Parolanı sıfırla',
            $html,
            $this->basePath,
            breadcrumbs: new BreadcrumbTrail([
                new BreadcrumbItem('Ana Sayfa', '/'),
                new BreadcrumbItem('Giriş yap', '/login'),
                new BreadcrumbItem('Parolanı sıfırla'),
            ]),
        ))->withHeader('Cache-Control', 'no-store');
    }

    private function sent(): Response
    {
        $html = '<section class="auth-entry"><div class="auth-entry-card card"><div class="auth-entry-copy">'
            . '<span class="forum-eyebrow">HESAP</span><h1>Talep alındı</h1>'
            . '<p>Bilgiler bir hesapla eşleşiyorsa parola sıfırlama bağlantısı gönderildi.</p></div>'
            . '<div class="auth-entry-actions"><a class="fx-btn fx-btn--primary" href="'
            . self::e($this->basePath->prepend('/login')) . '">Girişe dön</a></div></div></section>';
        return Response::html(ProfileHtml::page('Parola sıfırlama talebi', $html, $this->basePath))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function unavailable(): Response
    {
        $html = '<section class="auth-entry"><div class="auth-entry-card card"><div class="auth-entry-copy">'
            . '<span class="forum-eyebrow">HESAP</span><h1>Parola kurtarma kullanılamıyor</h1>'
            . '<p>Güvenli e-posta teslimi yapılandırılmadan parola sıfırlama bağlantısı üretilemez.</p></div>'
            . '<div class="auth-entry-actions"><a class="fx-btn" href="'
            . self::e($this->basePath->prepend('/login')) . '">Girişe dön</a></div></div></section>';
        return Response::html(ProfileHtml::page('Parola kurtarma kullanılamıyor', $html, $this->basePath), 503)
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
