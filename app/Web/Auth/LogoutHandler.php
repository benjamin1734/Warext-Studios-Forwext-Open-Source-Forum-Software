<?php

declare(strict_types=1);

namespace Forwext\App\Web\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Http\Cookie\ResponseCookie;
use Forwext\Core\Http\Cookie\SameSite;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use InvalidArgumentException;

final readonly class LogoutHandler implements RequestHandlerInterface
{
    public function __construct(
        private AuthSessionManager $sessions,
        private BasePath $basePath,
        private string $sessionCookieName,
    ) {
        if ($sessionCookieName === '') {
            throw new InvalidArgumentException('Logout handler session cookie name is invalid.');
        }
    }

    public function handle(Request $request): Response
    {
        if ($request->method() === HttpMethod::Post) {
            $sessionId = $request->cookie($this->sessionCookieName);
            if (is_string($sessionId) && preg_match('/^s_[A-Za-z0-9_-]{43}$/D', $sessionId) === 1) {
                $this->sessions->revoke($sessionId);
            }

            return Response::text('', 303)
                ->withHeader('Location', $this->basePath->prepend('/'))
                ->withHeader('Cache-Control', 'no-store')
                ->withCookie($this->expiredCookie());
        }

        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        $html = '<section class="auth-entry"><div class="auth-entry-card card">'
            . '<div class="auth-entry-copy"><span class="forum-eyebrow">HESAP</span><h1>Çıkış yap</h1>'
            . '<p>Bu tarayıcıdaki Forwext oturumunu güvenli biçimde sonlandır.</p></div>'
            . '<form class="auth-entry-actions" method="post" action="'
            . ProfileHtml::escape($this->basePath->prepend('/logout')) . '">'
            . '<input type="hidden" name="_csrf" value="' . ProfileHtml::escape($token) . '">'
            . '<a class="fx-btn" href="' . ProfileHtml::escape($this->basePath->prepend('/')) . '">Vazgeç</a>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Çıkış yap</button></form></div></section>';

        return Response::html(ProfileHtml::page(
            'Çıkış yap',
            $html,
            $this->basePath,
            breadcrumbs: new BreadcrumbTrail([
                new BreadcrumbItem('Ana Sayfa', '/'),
                new BreadcrumbItem('Çıkış yap'),
            ]),
            authenticated: true,
        ))->withHeader('Cache-Control', 'no-store');
    }

    private function expiredCookie(): ResponseCookie
    {
        return new ResponseCookie(
            $this->sessionCookieName,
            '',
            expires: new DateTimeImmutable('@0', new DateTimeZone('UTC')),
            maxAge: 0,
            path: '/',
            secure: true,
            httpOnly: true,
            sameSite: SameSite::Lax,
        );
    }
}
