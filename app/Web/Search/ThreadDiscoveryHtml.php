<?php

declare(strict_types=1);

namespace Forwext\App\Web\Search;

use DateTimeZone;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Search\Discovery\DiscoveryMode;
use Forwext\Core\Search\Discovery\DiscoveryThread;

final class ThreadDiscoveryHtml
{
    /** @param list<DiscoveryThread> $threads */
    public static function page(
        array $threads,
        DiscoveryMode $mode,
        int $page,
        bool $hasMore,
        BasePath $basePath,
        DateTimeZone $timezone,
    ): string {
        [$title, $description] = self::copy($mode);
        $body = '<section class="thread-discovery discovery-page">'
            . '<header class="surface-head thread-discovery-head"><div>'
            . '<span class="forum-eyebrow">NELER YENİ?</span><h1>' . self::e($title) . '</h1>'
            . '<p>' . self::e($description) . '</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/search?tab=forum')) . '">İçerik ara</a>'
            . '</header>'
            . self::tabs($mode, $basePath)
            . '<section class="surface-panel thread-discovery-panel">'
            . '<div class="thread-discovery-list">' . self::rows($threads, $basePath, $timezone) . '</div>'
            . self::pagination($mode, $page, $hasMore, $basePath)
            . '</section></section>';

        return ProfileHtml::page(
            $title,
            $body,
            $basePath,
            authenticated: true,
        );
    }

    public static function authenticationRequired(BasePath $basePath): string
    {
        $body = '<section class="thread-discovery discovery-page"><div class="surface-panel surface-empty">'
            . '<h1>Oturum gerekli</h1><p>Yeni ve kişisel içerik akışlarını görmek için giriş yapmalısın.</p>'
            . '<a class="fx-btn fx-btn--primary" href="' . self::e($basePath->prepend('/login')) . '">Giriş yap</a>'
            . '</div></section>';

        return ProfileHtml::page('Neler yeni?', $body, $basePath);
    }

    public static function permissionDenied(BasePath $basePath): string
    {
        $body = '<section class="thread-discovery discovery-page"><div class="surface-panel surface-empty">'
            . '<h1>Bu akışa erişilemiyor</h1><p>Hesabının içerik keşfi izni bu görünüm için yeterli değil.</p>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/activity')) . '">Son hareketliliğe dön</a>'
            . '</div></section>';

        return ProfileHtml::page('Neler yeni?', $body, $basePath, authenticated: true);
    }

    /** @param list<DiscoveryThread> $threads */
    private static function rows(array $threads, BasePath $basePath, DateTimeZone $timezone): string
    {
        if ($threads === []) {
            return '<div class="surface-empty"><strong>Bu akışta içerik yok.</strong>'
                . '<span>Erişebildiğin forumlarda uygun bir konu oluştuğunda burada görünecek.</span></div>';
        }

        $html = '';
        foreach ($threads as $thread) {
            $href = $basePath->prepend('/threads/' . rawurlencode($thread->threadId->value()));
            $created = $thread->createdAt->setTimezone($timezone);
            $activity = $thread->activityAt->setTimezone($timezone);
            $badges = '';
            if ($thread->unread) {
                $badges .= '<span class="thread-discovery-badge is-unread">Okunmamış</span>';
            }
            if ($thread->featured) {
                $badges .= '<span class="thread-discovery-badge">Öne çıkan</span>';
            }

            $html .= '<article class="thread-discovery-row' . ($thread->unread ? ' is-unread' : '') . '">'
                . '<div class="thread-discovery-main"><div class="thread-discovery-badges">' . $badges . '</div>'
                . '<h2><a href="' . self::e($href) . '">' . self::e($thread->title) . '</a></h2>'
                . '<div class="thread-discovery-meta"><span>Oluşturuldu '
                . '<time datetime="' . self::e($thread->createdAt->format(DATE_ATOM)) . '">'
                . self::e($created->format('d.m.Y H:i')) . '</time></span>'
                . '<span>Son hareket <time datetime="' . self::e($thread->activityAt->format(DATE_ATOM)) . '">'
                . self::e($activity->format('d.m.Y H:i')) . '</time></span></div></div>'
                . '<div class="thread-discovery-stats"><div><strong>' . $thread->replyCount()
                . '</strong><span>Yanıt</span></div><div><strong>' . $thread->recentPostCount
                . '</strong><span>7 gün</span></div></div></article>';
        }

        return $html;
    }

    private static function tabs(DiscoveryMode $mode, BasePath $basePath): string
    {
        $tabs = [
            'new' => ['Yeni konular', DiscoveryMode::New],
            'unread' => ['Okunmamış', DiscoveryMode::Unread],
            'trending' => ['Gündem', DiscoveryMode::Trending],
            'featured' => ['Öne çıkanlar', DiscoveryMode::Featured],
            'recent' => ['Son hareketli', DiscoveryMode::RecentActivity],
        ];

        $html = '<nav class="tabs surface-tabs thread-discovery-tabs" aria-label="Konu keşif akışları">';
        foreach ($tabs as $key => [$label, $tabMode]) {
            $href = $basePath->prepend('/activity/threads/' . $key);
            $html .= '<a' . ($mode === $tabMode ? ' aria-current="page"' : '') . ' href="'
                . self::e($href) . '">' . self::e($label) . '</a>';
        }
        return $html . '</nav>';
    }

    private static function pagination(
        DiscoveryMode $mode,
        int $page,
        bool $hasMore,
        BasePath $basePath,
    ): string {
        if ($page === 1 && !$hasMore) {
            return '';
        }

        $modePath = match ($mode) {
            DiscoveryMode::New => 'new',
            DiscoveryMode::Unread => 'unread',
            DiscoveryMode::Trending => 'trending',
            DiscoveryMode::Featured => 'featured',
            DiscoveryMode::RecentActivity => 'recent',
        };
        $base = $basePath->prepend('/activity/threads/' . $modePath);
        $html = '<nav class="surface-pagination" aria-label="Konu keşif sayfaları">';
        if ($page > 1) {
            $html .= '<a class="fx-btn" href="' . self::e($base . '?page=' . ($page - 1)) . '">Önceki</a>';
        }
        $html .= '<span aria-current="page">Sayfa ' . $page . '</span>';
        if ($hasMore) {
            $html .= '<a class="fx-btn fx-btn--primary" href="' . self::e($base . '?page=' . ($page + 1))
                . '">Sonraki</a>';
        }

        return $html . '</nav>';
    }

    /** @return array{0:string,1:string} */
    private static function copy(DiscoveryMode $mode): array
    {
        return match ($mode) {
            DiscoveryMode::New => ['Yeni konular', 'Erişebildiğin forumlarda en son açılan konular.'],
            DiscoveryMode::Unread => ['Okunmamış konular', 'Okuma durumuna göre henüz görmediğin veya yeni yanıt alan konular.'],
            DiscoveryMode::Trending => ['Gündemdeki konular', 'Son yedi gündeki görünür mesaj hareketine göre öne çıkan konular.'],
            DiscoveryMode::Featured => ['Öne çıkan konular', 'Forum yönetiminin öne çıkardığı görünür konular.'],
            DiscoveryMode::RecentActivity => ['Son hareketli konular', 'Erişebildiğin forumlardaki en güncel konu hareketleri.'],
        };
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
