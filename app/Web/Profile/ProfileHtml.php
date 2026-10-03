<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use Forwext\Core\Ui\DesignToken\DesignTokenCssCompiler;
use Forwext\Core\Ui\Appearance\ComponentAppearanceCssCompiler;
use Forwext\Core\Ui\Appearance\Background\BackgroundCssCompiler;
use Forwext\Core\Ui\Appearance\Background\BackgroundRegistry;
use Forwext\Core\Ui\Appearance\ComponentAppearanceRegistry;
use Forwext\Core\Ui\Responsive\ResponsiveCssCompiler;
use Forwext\Core\Ui\Responsive\ResponsiveRegistry;
use Forwext\Core\Ui\Navigation\NavigationRegistry;
use Forwext\Core\Ui\Widget\WidgetContext;
use Forwext\Core\Ui\Widget\WidgetRegistry;
use Forwext\Core\Ui\Widget\WidgetRenderService;

final class ProfileHtml
{
    public static function page(
        string $title,
        string $content,
        BasePath $basePath,
        ?NavigationRegistry $navigation = null,
        ?BreadcrumbTrail $breadcrumbs = null,
        bool $authenticated = false,
        ?WidgetRenderService $widgetRenderer = null,
        ?string $viewerId = null,
        string $headAssets = '',
    ): string {
        $safeTitle = self::escape($title);
        $home = self::escape($basePath->prepend('/'));
        $navigation ??= NavigationRegistry::withCoreDefaults();
        $visibleNavigation = [];
        foreach ($navigation->visible($authenticated) as $item) {
            $visibleNavigation[$item->key] = $item;
        }

        $navHref = static function (string $path) use ($basePath): string {
            return self::escape($basePath->prepend($path));
        };
        $navItem = static function (string $key, string $label, string $path, string $extra = '') use ($navHref): string {
            return '<a data-nav-key="' . self::escape($key) . '" href="' . $navHref($path) . '"' . $extra . '>'
                . self::escape($label) . '</a>';
        };
        $visibleNavItem = static function (
            string $key,
            string $fallbackLabel,
            string $fallbackPath,
            string $extra = '',
        ) use ($visibleNavigation, $navItem): string {
            if (!isset($visibleNavigation[$key])) {
                return '';
            }
            $item = $visibleNavigation[$key];
            return $navItem($key, $item->label ?: $fallbackLabel, $item->path ?: $fallbackPath, $extra);
        };

        $primaryNav = $navItem('home', 'Ana Sayfa', '/', ' data-nav-section-link="home"')
            . $visibleNavItem('forums', 'Forumlar', '/forums', ' data-nav-section-link="forums"')
            . ($authenticated
                ? $navItem('activity', 'Neler yeni?', '/activity', ' data-nav-section-link="whatsnew"')
                : '')
            . $visibleNavItem('marketplace', 'Marketplace', '/marketplace', ' data-nav-section-link="marketplace"')
            . $visibleNavItem('members', 'Üyeler', '/members', ' data-nav-section-link="members"');

        $knownKeys = [
            'forums' => true,
            'search' => true,
            'members' => true,
            'members.online' => true,
            'portfolio' => true,
            'giveaways' => true,
            'marketplace' => true,
            'faq' => true,
            'account.own' => true,
            'security.own' => true,
            'conversations.own' => true,
            'referrals.own' => true,
            'subscriptions.own' => true,
            'notifications.own' => true,
            'bugs.mine' => true,
            'forum.stats' => true,
        ];
        $extraNavigation = '';
        foreach ($visibleNavigation as $item) {
            if (!isset($knownKeys[$item->key])) {
                $extraNavigation .= $navItem($item->key, $item->label, $item->path);
            }
        }

        $moreNav = $visibleNavItem('faq', 'SSS', '/faq');
        if (!isset($visibleNavigation['marketplace'])) {
            $moreNav .= $visibleNavItem('portfolio', 'Portfolyo', '/portfolio')
                . $visibleNavItem('giveaways', 'Çekilişler', '/giveaways');
        }
        $moreNav .= $extraNavigation;
        if ($moreNav !== '') {
            $primaryNav .= '<details class="nav-primary-menu" data-nav-section-link="more">'
                . '<summary>Diğer <span aria-hidden="true">⌄</span></summary>'
                . '<div class="nav-primary-popover">' . $moreNav . '</div></details>';
        }

        $forumSubNav = $visibleNavItem('forums', 'Forum listesi', '/forums')
            . $visibleNavItem('search', 'Forumlarda ara', '/search')
            . ($authenticated ? $navItem('forums.activity', 'Yeni içerikler', '/activity') : '');

        $whatsNewSubNav = $authenticated
            ? $navItem('activity.latest', 'Son hareketlilik', '/activity')
                . $navItem('activity.search', 'İçerik ara', '/search')
                . (isset($visibleNavigation['giveaways'])
                    ? $navItem('activity.giveaways', 'Çekilişler', '/giveaways')
                    : '')
            : '';

        $marketplaceSubNav = $visibleNavItem('marketplace', 'İlanlar', '/marketplace')
            . (isset($visibleNavigation['portfolio'])
                ? $navItem('marketplace.portfolio', 'Portfolyo', '/portfolio')
                : '')
            . (isset($visibleNavigation['giveaways'])
                ? $navItem('marketplace.giveaways', 'Çekilişler', '/giveaways')
                : '');

        $membersSubNav = $visibleNavItem('members', 'Kayıtlı üyeler', '/members')
            . (isset($visibleNavigation['members.online'])
                ? $navItem('members.online', 'Çevrimiçi üyeler', '/members/online')
                : '')
            . (isset($visibleNavigation['forum.stats'])
                ? $navItem('members.stats', 'İstatistikler', '/stats')
                : '');

        $homeSubNav = $visibleNavItem('forums', 'Forumlar', '/forums')
            . $visibleNavItem('search', 'Ara', '/search')
            . $visibleNavItem('faq', 'SSS', '/faq');

        $accountSubNav = '';
        $accountNav = '';
        foreach ([
            'account.own' => ['Hesap merkezi', '/account'],
            'security.own' => ['Güvenlik', '/account/security'],
            'conversations.own' => ['Özel mesajlar', '/account/conversations'],
            'referrals.own' => ['Davetlerim', '/account/referrals'],
            'subscriptions.own' => ['Yükseltmeler', '/account/upgrades'],
            'bugs.mine' => ['Hata bildirimlerim', '/bugs'],
        ] as $key => [$label, $path]) {
            if (isset($visibleNavigation[$key])) {
                $accountNav .= $navItem($key, $label, $path);
                $accountSubNav .= $navItem('sub.' . $key, $label, $path);
            }
        }

        $conversationNav = '';
        if (isset($visibleNavigation['conversations.own'])) {
            $conversationNav = '<a class="nav-icon-link nav-icon-link--messages" data-nav-key="conversations.own" href="'
                . $navHref('/account/conversations') . '" aria-label="Özel mesajlar" title="Özel mesajlar">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H8l-4 3v-3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm1.1 3.2 6.9 4.6 6.9-4.6-1-1.5L12 10.6 6.1 6.7l-1 1.5Z"/></svg>'
                . '<span class="sr-only">Özel mesajlar</span></a>';
        }

        $notificationNav = '';
        if (isset($visibleNavigation['notifications.own'])) {
            $notificationNav = '<a class="nav-icon-link nav-icon-link--alerts" data-nav-key="notifications.own" href="'
                . $navHref('/account/notifications') . '" aria-label="Bildirimler" title="Bildirimler">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22a2.6 2.6 0 0 0 2.45-1.75h-4.9A2.6 2.6 0 0 0 12 22Zm7-5.25-1.4-1.65V10a5.62 5.62 0 0 0-4.35-5.48V3.7a1.25 1.25 0 1 0-2.5 0v.82A5.62 5.62 0 0 0 6.4 10v5.1L5 16.75V18h14v-1.25Z"/></svg>'
                . '<span class="sr-only">Bildirimler</span></a>';
        }

        $searchIcon = '<a class="nav-icon-link nav-icon-link--search" data-nav-key="quick.search" href="'
            . $navHref('/search') . '" aria-label="Ara" title="Ara">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 4a6.5 6.5 0 1 0 3.96 11.65L19.8 21l1.2-1.2-5.35-5.34A6.5 6.5 0 0 0 10.5 4Zm0 1.8a4.7 4.7 0 1 1 0 9.4 4.7 4.7 0 0 1 0-9.4Z"/></svg>'
            . '<span class="sr-only">Ara</span></a>';

        if ($authenticated) {
            $userTools = '<div class="nav-user-tools nav-user-tools--member">'
                . '<details class="nav-account-menu"><summary aria-label="Hesap menüsü">'
                . '<span class="nav-user-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9Zm0 2c-4.25 0-7.5 2.14-7.5 5v1.5h15V19c0-2.86-3.25-5-7.5-5Z"/></svg></span>'
                . '<span class="nav-account-label">Hesabım</span><span class="nav-chevron" aria-hidden="true">⌄</span></summary>'
                . '<div class="nav-account-popover" aria-label="Hesap seçenekleri">' . $accountNav
                . '<a data-nav-key="auth.logout" href="' . $navHref('/logout') . '">Çıkış yap</a>'
                . '</div></details>' . $conversationNav . $notificationNav . $searchIcon . '</div>';
        } else {
            $userTools = '<div class="nav-user-tools nav-user-tools--guest">'
                . '<a class="nav-auth-link" data-nav-key="auth.login" href="' . $navHref('/login') . '">Giriş yap</a>'
                . '<a class="nav-auth-link nav-auth-link--primary" data-nav-key="auth.register" href="'
                . $navHref('/register') . '">Kayıt ol</a>' . $searchIcon . '</div>';
        }

        $secondaryNav = '<nav class="nav-secondary" data-forwext-subnav aria-label="İkincil navigasyon">'
            . '<div class="nav-secondary-group" data-nav-section="home" hidden>' . $homeSubNav . '</div>'
            . '<div class="nav-secondary-group" data-nav-section="forums" hidden>' . $forumSubNav . '</div>'
            . '<div class="nav-secondary-group" data-nav-section="whatsnew" hidden>' . $whatsNewSubNav . '</div>'
            . '<div class="nav-secondary-group" data-nav-section="marketplace" hidden>' . $marketplaceSubNav . '</div>'
            . '<div class="nav-secondary-group" data-nav-section="members" hidden>' . $membersSubNav . '</div>'
            . '<div class="nav-secondary-group" data-nav-section="more" hidden>' . $moreNav . '</div>'
            . '<div class="nav-secondary-group" data-nav-section="account" hidden>' . $accountSubNav . '</div>'
            . '</nav>';

        $widgetRenderer ??= new WidgetRenderService(WidgetRegistry::withCoreDefaults());
        $widgetContext = new WidgetContext($title, 'tr', $authenticated, $viewerId);
        $pageBefore = $widgetRenderer->renderSlot('page.before', $widgetContext);
        $headerBefore = $widgetRenderer->renderSlot('header.before', $widgetContext);
        $headerAfter = $widgetRenderer->renderSlot('header.after', $widgetContext);
        $mainBefore = $widgetRenderer->renderSlot('main.before', $widgetContext);
        $mainAfter = $widgetRenderer->renderSlot('main.after', $widgetContext);
        $sidebar = $widgetRenderer->renderSlot('sidebar.primary', $widgetContext);
        $footerBefore = $widgetRenderer->renderSlot('footer.before', $widgetContext);
        $footerAfter = $widgetRenderer->renderSlot('footer.after', $widgetContext);
        $pageAfter = $widgetRenderer->renderSlot('page.after', $widgetContext);

        if ($breadcrumbs === null && $title !== 'Ana Sayfa') {
            $breadcrumbs = BreadcrumbTrail::page($title);
        }
        $breadcrumbHtml = self::breadcrumbs($breadcrumbs, $basePath);
        $musicScript = self::escape($basePath->prepend('/assets/profile-music.js'));
        $notificationSoundScript = self::escape($basePath->prepend('/assets/notification-sound.js'));
        $notificationSettingsScript = self::escape($basePath->prepend('/assets/notification-settings.js'));
        $notificationRealtimeScript = self::escape($basePath->prepend('/assets/notification-realtime.js'));
        $threadInteractionsScript = self::escape($basePath->prepend('/assets/thread-interactions.js'));
        $profileRelationshipsScript = self::escape($basePath->prepend('/assets/profile-relationships.js'));
        $profileActivityWallScript = self::escape($basePath->prepend('/assets/profile-activity-wall.js'));
        $presenceScript = self::escape($basePath->prepend('/assets/presence-heartbeat.js'));
        $presenceEndpoint = self::escape($basePath->prepend('/account/presence/heartbeat'));
        $presenceSettingsScript = self::escape($basePath->prepend('/assets/presence-settings.js'));
        $bugReportScript = self::escape($basePath->prepend('/assets/bug-report-link.js'));
        $mobileNavScript = self::escape($basePath->prepend('/assets/mobile-nav.js'));
        $siteBaseStylesheet = self::escape($basePath->prepend('/assets/site-base.css'));
        $siteComponentsStylesheet = self::escape($basePath->prepend('/assets/site-components.css'));
        $sitePagesStylesheet = self::escape($basePath->prepend('/assets/site-pages.css'));
        $bugReportLink = $authenticated
            ? '<a class="bug-report-fab" data-bug-report-link href="' . self::escape($basePath->prepend('/bugs/report'))
                . '" aria-label="Hata bildir" title="Hata bildir">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
                . '<path d="M9 3h6l1 2h3v2h-2.2c.5.9.8 1.9.9 3H21v2h-3.3c-.1.7-.3 1.4-.6 2H21v2h-5.1c-1 1.2-2.3 2-3.9 2s-2.9-.8-3.9-2H3v-2h3.9c-.3-.6-.5-1.3-.6-2H3v-2h3.3c.1-1.1.4-2.1.9-3H5V5h3l1-2Zm3 4a3 3 0 0 0-3 3v3a3 3 0 0 0 6 0v-3a3 3 0 0 0-3-3Z"/>'
                . '</svg></a>'
            : '';

        $pageBeforeHtml = $pageBefore === ''
            ? ''
            : '<div class="ui-page-slot ui-page-slot--before">' . $pageBefore . '</div>';
        $pageAfterHtml = $pageAfter === ''
            ? ''
            : '<div class="ui-page-slot ui-page-slot--after">' . $pageAfter . '</div>';

        $mainHtml = '<main id="main-content" class="wrap">'
            . $mainBefore . $breadcrumbHtml . $content . $mainAfter . '</main>';
        if ($sidebar !== '') {
            $mainHtml = '<div class="layout-shell">' . $mainHtml
                . '<aside class="layout-sidebar" data-forwext-responsive-target="sidebar" aria-label="Kenar çubuğu">'
                . $sidebar . '</aside></div>';
        }

        $footerHtml = '<footer class="site-footer"><div class="footerin">'
            . $footerBefore
            . '<div class="core-brand-footer"><strong>Forwext</strong><span>Açık kaynak, modern topluluk forum altyapısı.</span></div>'
            . $footerAfter . '</div></footer>';

        return '<!doctype html><html lang="tr" dir="ltr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="forwext-presence-endpoint" content="' . $presenceEndpoint . '">'
            . '<title>' . $safeTitle . ' · Forwext</title><style>' . self::appearanceCss($basePath)
            . '</style>'
            . '<link rel="stylesheet" href="' . $siteBaseStylesheet . '">'
            . '<link rel="stylesheet" href="' . $siteComponentsStylesheet . '">'
            . '<link rel="stylesheet" href="' . $sitePagesStylesheet . '">' . $headAssets
            . '</head><body data-forwext-background-scope="site">'
            . '<a class="skip-link" href="#main-content">İçeriğe geç</a>' . $pageBeforeHtml
            . '<header class="top" data-forwext-background-scope="header">' . $headerBefore
            . '<div class="top-main"><div class="topin">'
            . '<a class="brand" href="' . $home . '"><span class="brand-mark" aria-hidden="true">F</span>'
            . '<span class="brand-copy"><strong>Forwext</strong></span></a>'
            . '<button class="nav-toggle" type="button" data-forwext-nav-toggle aria-expanded="false" aria-controls="forwext-primary-navigation"><span aria-hidden="true">☰</span><span>Menü</span></button>'
            . '<div id="forwext-primary-navigation" class="nav-shell" data-forwext-primary-navigation data-mobile-open="0">'
            . '<nav class="nav-primary" aria-label="Ana navigasyon">' . $primaryNav . '</nav>' . $userTools . '</div></div></div>'
            . '<div class="top-sub" data-forwext-subnav-shell hidden><div class="top-subin">' . $secondaryNav . '</div></div>'
            . $headerAfter . '</header>' . $mainHtml . $footerHtml . $pageAfterHtml . $bugReportLink
            . '<script src="' . $musicScript . '" defer></script>'
            . '<script src="' . $notificationSoundScript . '" defer></script>'
            . '<script src="' . $notificationSettingsScript . '" defer></script>'
            . '<script src="' . $notificationRealtimeScript . '" defer></script>'
            . '<script src="' . $threadInteractionsScript . '" defer></script>'
            . '<script src="' . $profileRelationshipsScript . '" defer></script>'
            . '<script src="' . $profileActivityWallScript . '" defer></script>'
            . '<script src="' . $presenceScript . '" defer></script>'
            . '<script src="' . $presenceSettingsScript . '" defer></script>'
            . '<script src="' . $bugReportScript . '" defer></script>'
            . '<script src="' . $mobileNavScript . '" defer></script></body></html>';
    }

    private static function appearanceCss(BasePath $basePath): string
    {
        static $cache = [];

        $cacheKey = $basePath->prepend('/');
        if (!isset($cache[$cacheKey])) {
            $catalog = DesignTokenCatalog::coreDefaults();
            $cache[$cacheKey] = (new DesignTokenCssCompiler())->compile($catalog)
                . (new ComponentAppearanceCssCompiler())->compile(
                    ComponentAppearanceRegistry::coreDefaults($catalog),
                    $catalog,
                )
                . (new BackgroundCssCompiler())->compile(
                    BackgroundRegistry::coreDefaults($catalog),
                    $catalog,
                    $basePath,
                )
                . (new ResponsiveCssCompiler())->compile(ResponsiveRegistry::coreDefaults());
        }

        return $cache[$cacheKey];
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function memberPath(BasePath $basePath, string $username): string
    {
        return $basePath->prepend('/members/' . rawurlencode($username));
    }

    public static function initial(string $username): string
    {
        if (preg_match('/^./us', $username, $matches) === 1) {
            return self::escape($matches[0]);
        }
        return '?';
    }

    private static function breadcrumbs(?BreadcrumbTrail $trail, BasePath $basePath): string
    {
        if ($trail === null || $trail->items === []) {
            return '';
        }
        $parts = [];
        foreach ($trail->items as $index => $item) {
            if ($item->path !== null && $index < count($trail->items) - 1) {
                $parts[] = '<a href="' . self::escape($basePath->prepend($item->path)) . '">'
                    . self::escape($item->label) . '</a>';
            } else {
                $parts[] = '<span' . ($index === count($trail->items) - 1 ? ' aria-current="page"' : '') . '>'
                    . self::escape($item->label) . '</span>';
            }
        }
        return '<nav class="breadcrumbs" aria-label="Breadcrumb">'
            . implode('<span class="sep" aria-hidden="true">/</span>', $parts) . '</nav>';
    }
}
