<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Auth\AuthException;
use Forwext\Core\Auth\Password\PasswordResetService;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use InvalidArgumentException;

final readonly class PasswordResetHandler implements RequestHandlerInterface
{
    public function __construct(
        private PasswordResetService $passwordReset,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($this->viewers->resolve($request) !== null) {
            return $this->redirectHome();
        }
        if ($request->method() === HttpMethod::Post) {
            return $this->submit($request);
        }
        $token = $request->query()['token'] ?? null;
        return $this->view($request, is_string($token) ? $token : '');
    }

    private function submit(Request $request): Response
    {
        $body = $request->parsedBody();
        $token = $body['token'] ?? null;
        $password = $body['password'] ?? null;
        $confirmation = $body['password_confirmation'] ?? null;
        if (!is_string($token) || !is_string($password) || !is_string($confirmation)
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1
            || !hash_equals($password, $confirmation)
        ) {
            return $this->result(false);
        }

        try {
            return $this->result($this->passwordReset->reset($token, $password));
        } catch (AuthException|InvalidArgumentException) {
            return $this->result(false);
        }
    }

    private function view(Request $request, string $token): Response
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return $this->result(false);
        }
        $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($csrf) || $csrf === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $html = '<section class="auth-entry"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">HESAP</span><h1>Yeni parola belirle</h1>'
            . '<p>Hesabın için yeni ve güçlü bir parola belirle.</p></div>'
            . '<form class="auth-entry-form" method="post" action="' . self::e($this->basePath->prepend('/reset-password')) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="token" value="' . self::e($token) . '">'
            . '<label><span>Yeni parola</span><input type="password" name="password" autocomplete="new-password" maxlength="1024" required></label>'
            . '<label><span>Yeni parolayı tekrar et</span><input type="password" name="password_confirmation" autocomplete="new-password" maxlength="1024" required></label>'
            . '<button class="fx-btn fx-btn--primary auth-entry-submit" type="submit">Parolayı güncelle</button></form>'
            . '</div></section>';

        return Response::html(ProfileHtml::page(
            'Yeni parola belirle',
            $html,
            $this->basePath,
            breadcrumbs: new BreadcrumbTrail([
                new BreadcrumbItem('Ana Sayfa', '/'),
                new BreadcrumbItem('Giriş yap', '/login'),
                new BreadcrumbItem('Yeni parola belirle'),
            ]),
        ))->withHeader('Cache-Control', 'no-store');
    }

    private function result(bool $success): Response
    {
        $title = $success ? 'Parolan güncellendi' : 'Parola güncellenemedi';
        $message = $success
            ? 'Parolan güvenli biçimde güncellendi. Eski kalıcı oturumlar geçersiz kılındı.'
            : 'Bağlantı geçersiz, kullanılmış veya süresi dolmuş olabilir; yeni parola da güvenlik politikasına uymalıdır.';
        $html = '<section class="auth-entry"><div class="auth-entry-card card"><div class="auth-entry-copy">'
            . '<span class="forum-eyebrow">HESAP</span><h1>' . self::e($title) . '</h1><p>' . self::e($message) . '</p></div>'
            . '<div class="auth-entry-actions"><a class="fx-btn fx-btn--primary" href="'
            . self::e($this->basePath->prepend('/login')) . '">Giriş sayfasına git</a></div></div></section>';
        return Response::html(ProfileHtml::page($title, $html, $this->basePath), $success ? 200 : 422)
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
