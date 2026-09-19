<?php

declare(strict_types=1);

namespace Forwext\App\Web\Editor;

use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Content\Spellcheck\SpellcheckAccessDeniedException;
use Forwext\Core\Content\Spellcheck\SpellcheckLanguage;
use Forwext\Core\Content\Spellcheck\SpellcheckService;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class SpellcheckDictionaryHandler implements RequestHandlerInterface
{
    public function __construct(
        private SpellcheckService $spellcheck,
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
            return $this->mutate($request, $viewerId);
        }

        $language = $request->query()['language'] ?? 'tr-tr';
        if (!is_string($language)) {
            return Response::text('Invalid language.', 422)->withHeader('Cache-Control', 'no-store');
        }

        $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if (!is_string($token) || $token === '') {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $language = SpellcheckLanguage::normalize($language);
            $words = $this->spellcheck->dictionary($viewerId, $language);
        } catch (SpellcheckAccessDeniedException) {
            return Response::text('Permission denied.', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Invalid language.', 422)->withHeader('Cache-Control', 'no-store');
        }

        $canOwn = $this->spellcheck->canManageOwnDictionary($viewerId);
        $canSite = $this->spellcheck->canManageSiteDictionary($viewerId);
        $notice = ($request->query()['updated'] ?? null) === '1'
            ? '<div class="notice success">Yazım denetimi sözlüğü güncellendi.</div>'
            : '';

        $body = '<section class="card settings"><h1>Yazım denetimi sözlüğü</h1>'
            . '<p class="muted">Kendi terimlerini denetimden hariç tutabilir; yetkin varsa site genelindeki sözlüğü de yönetebilirsin.</p>'
            . $notice
            . $this->section('Kişisel sözlük', 'user', $words['user'], $language, $token, $canOwn)
            . $this->section('Site sözlüğü', 'site', $words['site'], $language, $token, $canSite)
            . '</section>';

        return Response::html(ProfileHtml::page(
            'Yazım denetimi sözlüğü',
            $body,
            $this->basePath,
            authenticated: true,
        ))->withHeader('Cache-Control', 'private, no-store');
    }

    private function mutate(Request $request, \Forwext\Core\Domain\Entity\EntityId $viewerId): Response
    {
        $body = $request->parsedBody();
        $scope = $body['scope'] ?? null;
        $action = $body['action'] ?? null;
        $word = $body['word'] ?? null;
        $language = $body['language'] ?? 'tr-tr';
        if (!is_string($scope) || !is_string($action) || !is_string($word) || !is_string($language)) {
            return Response::text('Invalid request.', 400)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $language = SpellcheckLanguage::normalize($language);
            if ($scope === 'user' && $action === 'add') {
                $this->spellcheck->addUserWord($viewerId, $language, $word, HttpAuditRequestId::fromRequest($request));
            } elseif ($scope === 'user' && $action === 'remove') {
                $this->spellcheck->removeUserWord($viewerId, $language, $word, HttpAuditRequestId::fromRequest($request));
            } elseif ($scope === 'site' && $action === 'add') {
                $this->spellcheck->addSiteWord($viewerId, $language, $word, HttpAuditRequestId::fromRequest($request));
            } elseif ($scope === 'site' && $action === 'remove') {
                $this->spellcheck->removeSiteWord($viewerId, $language, $word, HttpAuditRequestId::fromRequest($request));
            } else {
                return Response::text('Invalid dictionary operation.', 400)
                    ->withHeader('Cache-Control', 'no-store');
            }
        } catch (SpellcheckAccessDeniedException) {
            return Response::text('Permission denied.', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Invalid dictionary word.', 422)->withHeader('Cache-Control', 'no-store');
        }

        return Response::text('', 303)
            ->withHeader(
                'Location',
                $this->basePath->prepend('/account/spellcheck-dictionary?language=' . rawurlencode($language) . '&updated=1'),
            )
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param list<string> $words */
    private function section(
        string $title,
        string $scope,
        array $words,
        string $language,
        string $token,
        bool $canManage,
    ): string {
        $html = '<section class="section"><h2>' . ProfileHtml::escape($title) . '</h2>';
        if ($words === []) {
            $html .= '<p class="muted">Henüz özel kelime yok.</p>';
        } else {
            $html .= '<div class="grid">';
            foreach ($words as $word) {
                $html .= '<div class="card"><strong>' . ProfileHtml::escape($word) . '</strong>';
                if ($canManage) {
                    $html .= $this->form($scope, 'remove', $word, $language, $token, 'Kaldır');
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        if ($canManage) {
            $html .= '<h3>Kelime ekle</h3>'
                . '<form method="post" action="' . ProfileHtml::escape($this->basePath->prepend('/account/spellcheck-dictionary')) . '">'
                . '<input type="hidden" name="_csrf" value="' . ProfileHtml::escape($token) . '">'
                . '<input type="hidden" name="scope" value="' . ProfileHtml::escape($scope) . '">'
                . '<input type="hidden" name="action" value="add">'
                . '<input type="hidden" name="language" value="' . ProfileHtml::escape($language) . '">'
                . '<label>Kelime <input name="word" maxlength="96" required autocomplete="off"></label> '
                . '<button type="submit">Ekle</button></form>';
        }
        return $html . '</section>';
    }

    private function form(
        string $scope,
        string $action,
        string $word,
        string $language,
        string $token,
        string $label,
    ): string {
        return '<form method="post" action="' . ProfileHtml::escape($this->basePath->prepend('/account/spellcheck-dictionary')) . '">'
            . '<input type="hidden" name="_csrf" value="' . ProfileHtml::escape($token) . '">'
            . '<input type="hidden" name="scope" value="' . ProfileHtml::escape($scope) . '">'
            . '<input type="hidden" name="action" value="' . ProfileHtml::escape($action) . '">'
            . '<input type="hidden" name="language" value="' . ProfileHtml::escape($language) . '">'
            . '<input type="hidden" name="word" value="' . ProfileHtml::escape($word) . '">'
            . '<button type="submit">' . ProfileHtml::escape($label) . '</button></form>';
    }
}
