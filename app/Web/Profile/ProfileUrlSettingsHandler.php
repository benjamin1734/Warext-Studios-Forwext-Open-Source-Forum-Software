<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Profile\Url\ProfileUrlException;
use Forwext\Core\Profile\Url\ProfileUrlService;
use Forwext\Core\Routing\BasePath;

final readonly class ProfileUrlSettingsHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileUrlService $urls,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $viewerId = $this->viewers->resolve($request);
        if ($viewerId === null) {
            return Response::text('Authentication required.', 401)
                ->withHeader('Cache-Control', 'no-store');
        }

        if ($request->method() === HttpMethod::Post) {
            return $this->update($request, $viewerId);
        }

        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)
                ->withHeader('Cache-Control', 'no-store');
        }

        $current = $this->urls->current($viewerId);
        $currentUrl = $current === null
            ? '<span class="muted">Henüz özel profil adresi seçilmedi.</span>'
            : '<a href="' . ProfileHtml::escape($this->basePath->prepend('/u/' . rawurlencode($current->slug->value()))) . '">'
                . ProfileHtml::escape($this->basePath->prepend('/u/' . $current->slug->value())) . '</a>';

        $notice = '';
        if (($request->query()['updated'] ?? null) === '1') {
            $notice = '<div class="notice success">Özel profil adresin güncellendi.</div>';
        } elseif (($request->query()['error'] ?? null) === '1') {
            $notice = '<div class="notice error">Bu adres kullanılamıyor veya değiştirme sınırına takıldı.</div>';
        }

        $action = ProfileHtml::escape($this->basePath->prepend('/account/profile-url'));
        $body = '<section class="card settings"><h1>Özel profil URL’si</h1>'
            . '<p class="muted">Kısa ve paylaşılabilir profil adresini seç. Eski adreslerin başka hesaba verilmez.</p>'
            . $notice
            . '<div class="current-url"><strong>Mevcut adres:</strong> ' . $currentUrl . '</div>'
            . '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . ProfileHtml::escape($token) . '">'
            . '<label for="profile-slug">Adres</label>'
            . '<div class="slugrow"><span>/u/</span><input id="profile-slug" name="slug" required minlength="3" maxlength="32" '
            . 'pattern="[A-Za-z0-9](?:[A-Za-z0-9-]{1,30}[A-Za-z0-9])" autocomplete="off" spellcheck="false" '
            . 'value="' . ProfileHtml::escape($current?->slug->value() ?? '') . '"></div>'
            . '<p class="muted">3–32 karakter; harf, rakam ve tek tire kullanılabilir. Sistem adları ve daha önce alınmış adresler kullanılamaz.</p>'
            . '<button type="submit">Adresi kaydet</button></form></section>';

        return Response::html(ProfileHtml::page('Özel profil URL’si', $body, $this->basePath))
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function update(Request $request, \Forwext\Core\Domain\Entity\EntityId $viewerId): Response
    {
        $slug = $request->parsedBody()['slug'] ?? null;
        if (!is_string($slug)) {
            return $this->redirectWithError();
        }

        try {
            $this->urls->assign(
                $viewerId,
                $viewerId,
                $slug,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (ProfileUrlException) {
            return $this->redirectWithError();
        }

        return Response::text('', 303)
            ->withHeader('Location', $this->basePath->prepend('/account/profile-url?updated=1'))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function redirectWithError(): Response
    {
        return Response::text('', 303)
            ->withHeader('Location', $this->basePath->prepend('/account/profile-url?error=1'))
            ->withHeader('Cache-Control', 'no-store');
    }
}
