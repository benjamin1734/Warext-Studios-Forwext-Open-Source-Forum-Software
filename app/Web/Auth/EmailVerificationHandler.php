<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Registration\EmailVerificationService;
use Forwext\Core\Registration\RegistrationException;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;

final readonly class EmailVerificationHandler implements RequestHandlerInterface
{
    public function __construct(
        private EmailVerificationService $verification,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($request->method() === HttpMethod::Post) {
            return $this->submit($request);
        }

        $token = $request->query()['token'] ?? null;
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return $this->result(false);
        }

        $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($csrf) || $csrf === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $html = '<section class="auth-entry"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">HESAP</span><h1>E-postanı doğrula</h1>'
            . '<p>Hesabının e-posta adresini doğrulamak için işlemi tamamla.</p></div>'
            . '<form class="auth-entry-actions" method="post" action="' . self::e($this->basePath->prepend('/verify-email')) . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="token" value="' . self::e($token) . '">'
            . '<a class="fx-btn" href="' . self::e($this->basePath->prepend('/')) . '">Vazgeç</a>'
            . '<button class="fx-btn fx-btn--primary" type="submit">E-postayı doğrula</button></form>'
            . '</div></section>';

        return Response::html(ProfileHtml::page(
            'E-postanı doğrula',
            $html,
            $this->basePath,
            breadcrumbs: new BreadcrumbTrail([
                new BreadcrumbItem('Ana Sayfa', '/'),
                new BreadcrumbItem('E-posta doğrulama'),
            ]),
        ))->withHeader('Cache-Control', 'no-store');
    }

    private function submit(Request $request): Response
    {
        $token = $request->parsedBody()['token'] ?? null;
        if (!is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) {
            return $this->result(false);
        }

        try {
            return $this->result($this->verification->verify($token));
        } catch (RegistrationException) {
            return $this->result(false);
        }
    }

    private function result(bool $verified): Response
    {
        $title = $verified ? 'E-posta doğrulandı' : 'Doğrulama bağlantısı geçersiz';
        $message = $verified
            ? 'E-posta adresin doğrulandı. Hesabın uygunsa artık giriş yapabilirsin.'
            : 'Bu doğrulama bağlantısı geçersiz, kullanılmış veya süresi dolmuş olabilir.';
        $html = '<section class="auth-entry"><div class="auth-entry-card card"><div class="auth-entry-copy">'
            . '<span class="forum-eyebrow">HESAP</span><h1>' . self::e($title) . '</h1><p>' . self::e($message) . '</p></div>'
            . '<div class="auth-entry-actions"><a class="fx-btn fx-btn--primary" href="'
            . self::e($this->basePath->prepend('/login')) . '">Giriş sayfasına git</a></div></div></section>';

        return Response::html(ProfileHtml::page($title, $html, $this->basePath), $verified ? 200 : 422)
            ->withHeader('Cache-Control', 'no-store');
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
