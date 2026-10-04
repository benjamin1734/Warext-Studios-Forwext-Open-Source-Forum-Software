<?php

declare(strict_types=1);

namespace Forwext\App\Web\Community;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Presence\PresenceService;
use Forwext\Core\Presence\PresenceVisibility;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbItem;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;

final readonly class OnlineUsersHandler implements RequestHandlerInterface
{
    public function __construct(
        private PresenceService $presence,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor !== null) {
            $this->presence->heartbeat($actor);
        }
        $users = $this->presence->online($actor !== null, 60);
        $cards = '';
        foreach ($users as $online) {
            $path = ProfileHtml::memberPath($this->basePath, $online->username);
            $cards .= '<a class="member-directory-card is-online" href="' . ProfileHtml::escape($path) . '">'
                . '<span class="avatar" aria-hidden="true">' . ProfileHtml::initial($online->username) . '</span>'
                . '<span class="member-directory-copy"><strong>' . ProfileHtml::escape($online->username) . '</strong>'
                . '<small><span class="online-dot" aria-hidden="true"></span>Şu anda çevrimiçi</small></span></a>';
        }
        $cards = $cards === ''
            ? '<div class="surface-empty">Görünür çevrimiçi kullanıcı bulunmuyor.</div>'
            : '<div class="member-directory-grid">' . $cards . '</div>';

        $settings = '';
        if ($actor !== null) {
            $current = $this->presence->visibility($actor);
            $action = ProfileHtml::escape($this->basePath->prepend('/account/presence'));
            $settings = '<form id="presence-settings" class="surface-panel presence-settings" data-presence-settings method="post" action="' . $action . '">'
                . '<label><span>Çevrimiçi görünürlüğüm</span><select name="visibility">'
                . self::option(PresenceVisibility::Hidden, $current, 'Gizli')
                . self::option(PresenceVisibility::Members, $current, 'Yalnız üyeler')
                . self::option(PresenceVisibility::Public, $current, 'Herkes')
                . '</select></label><button type="submit">Kaydet</button>'
                . '<span class="muted" data-presence-status aria-live="polite"></span></form>';
        }

        $body = '<section class="online-users-page discovery-page"><header class="surface-head online-users-head">'
            . '<div><span class="forum-eyebrow">TOPLULUK</span><h1>Çevrimiçi Kullanıcılar</h1>'
            . '<p>Son 5 dakika içinde aktif olan ve görünürlüğünü paylaşan profiller.</p></div>'
            . '<a class="fx-btn" href="' . ProfileHtml::escape($this->basePath->prepend('/members')) . '">Tüm üyeler</a>'
            . '</header>' . $settings . '<section class="surface-panel online-users-results">' . $cards
            . '</section></section>';
        $breadcrumbs = new BreadcrumbTrail([
            new BreadcrumbItem('Ana Sayfa', '/'),
            new BreadcrumbItem('Üyeler', '/members'),
            new BreadcrumbItem('Çevrimiçi'),
        ]);

        return Response::html(ProfileHtml::page(
            'Çevrimiçi Kullanıcılar',
            $body,
            $this->basePath,
            breadcrumbs: $breadcrumbs,
            authenticated: $actor !== null,
        ))->withHeader('Cache-Control', 'private, no-store');
    }

    private static function option(
        PresenceVisibility $value,
        PresenceVisibility $current,
        string $label,
    ): string {
        return '<option value="' . $value->value . '"' . ($value === $current ? ' selected' : '') . '>'
            . ProfileHtml::escape($label) . '</option>';
    }
}
