<?php

declare(strict_types=1);

namespace Forwext\App\Web\Search;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Search\DiscoveryUx\GlobalDiscoveryCategory;
use Forwext\Core\Search\DiscoveryUx\GlobalDiscoveryRegistry;
use Forwext\Core\Search\SearchHit;

final class SearchHtml
{
    /**
     * @param list<SearchHit> $hits
     * @param array<string,mixed> $query
     * @param list<string> $savedKeys
     */
    public static function page(
        BasePath $basePath,
        string $text,
        array $hits,
        array $query,
        ?string $error,
        array $savedKeys,
        int $page = 1,
        ?GlobalDiscoveryRegistry $discovery = null,
        string $selectedTab = GlobalDiscoveryRegistry::ALL,
        bool $authenticated = false,
        bool $hasMore = false,
    ): string {
        $discovery ??= GlobalDiscoveryRegistry::withCoreDefaults();
        $action = ProfileHtml::escape($basePath->prepend('/search'));
        $value = static fn (string $key): string => ProfileHtml::escape(
            is_string($query[$key] ?? null) ? (string) $query[$key] : '',
        );
        $advancedOpen = self::hasAdvancedFilters($query) ? ' open' : '';

        $content = '<section class="discovery-page"><header class="surface-head search-head"><div>'
            . '<span class="forum-eyebrow">KEŞİF</span><h1>Keşfet ve Ara</h1>'
            . '<p>Erişebildiğin forum, üye ve modül içeriklerini tek yerden ara.</p></div></header>'
            . self::tabs($basePath, $query, $discovery, $selectedTab)
            . '<section class="surface-panel search-panel"><form class="search-form" method="get" action="' . $action . '">'
            . '<input type="hidden" name="tab" value="' . ProfileHtml::escape($selectedTab) . '">'
            . '<label class="search-wide"><span>Arama</span><input name="q" maxlength="500" required value="'
            . ProfileHtml::escape($text) . '" placeholder="Ne arıyorsunuz?"></label>'
            . '<details class="search-wide"' . $advancedOpen . '><summary>Gelişmiş filtreler</summary>'
            . '<div class="search-form">'
            . '<label><span>İçerik tipi</span><input name="type" value="' . $value('type')
            . '" placeholder="Örn. thread,post"></label>'
            . '<label><span>Forum ID</span><input name="forum" value="' . $value('forum')
            . '" placeholder="Birden fazla: virgülle"></label>'
            . '<label><span>Kullanıcı ID</span><input name="user" value="' . $value('user')
            . '" placeholder="Yazar / üye"></label>'
            . '<label><span>Prefix ID</span><input name="prefix" value="' . $value('prefix') . '"></label>'
            . '<label><span>Tag ID</span><input name="tag" value="' . $value('tag') . '"></label>'
            . '<label><span>State</span><input name="state" value="' . $value('state')
            . '" placeholder="visible,active"></label>'
            . '<label><span>Konu tipi</span><input name="thread_type" value="' . $value('thread_type')
            . '" placeholder="discussion"></label>'
            . '<label><span>Güncellendi: başlangıç</span><input type="date" name="after" value="'
            . $value('after') . '"></label>'
            . '<label><span>Güncellendi: bitiş</span><input type="date" name="before" value="'
            . $value('before') . '"></label>';

        if ($savedKeys !== [] && $selectedTab === GlobalDiscoveryRegistry::ALL) {
            $currentSaved = is_string($query['saved'] ?? null) ? (string) $query['saved'] : '';
            $content .= '<label><span>Kayıtlı sorgu</span><select name="saved"><option value="">—</option>';
            foreach ($savedKeys as $key) {
                $selected = $currentSaved === $key ? ' selected' : '';
                $content .= '<option value="' . ProfileHtml::escape($key) . '"' . $selected . '>'
                    . ProfileHtml::escape($key) . '</option>';
            }
            $content .= '</select></label>';
        }

        $content .= '</div></details>'
            . '<div class="search-actions"><button type="submit">Ara</button><a href="' . $action
            . '">Filtreleri temizle</a></div></form></section>';

        if ($error !== null) {
            $content .= '<div class="search-alert" role="alert">' . ProfileHtml::escape($error) . '</div>';
        } elseif ($text !== '') {
            $content .= '<section class="search-results"><div class="search-result-head"><div>'
                . '<span class="forum-eyebrow">SONUÇLAR</span><h2>Arama sonuçları</h2></div>'
                . '<span class="muted">Sayfa ' . $page . '</span></div>'
                . self::results($basePath, $hits, $discovery, $selectedTab)
                . self::pagination($basePath, $query, $page, $hasMore)
                . '</section>';
        }

        $content .= '</section>';
        return ProfileHtml::page(
            'Keşfet ve Ara',
            $content,
            $basePath,
            authenticated: $authenticated,
        );
    }

    /** @param array<string,mixed> $query */
    private static function tabs(
        BasePath $basePath,
        array $query,
        GlobalDiscoveryRegistry $registry,
        string $selectedTab,
    ): string {
        $tabs = '<nav class="tabs surface-tabs search-tabs" aria-label="İçerik türleri">'
            . self::tabLink($basePath, $query, GlobalDiscoveryRegistry::ALL, 'Tümü', $selectedTab);
        foreach ($registry->categories() as $category) {
            $tabs .= self::tabLink($basePath, $query, $category->key, $category->label, $selectedTab);
        }
        return $tabs . '</nav>';
    }

    /** @param array<string,mixed> $query */
    private static function tabLink(
        BasePath $basePath,
        array $query,
        string $tab,
        string $label,
        string $selectedTab,
    ): string {
        $params = ['tab' => $tab];
        foreach (['q', 'forum', 'user', 'prefix', 'tag', 'state', 'thread_type', 'after', 'before'] as $key) {
            if (is_string($query[$key] ?? null) && $query[$key] !== '') {
                $params[$key] = (string) $query[$key];
            }
        }
        $href = $basePath->prepend('/search') . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return '<a' . ($tab === $selectedTab ? ' aria-current="page"' : '') . ' href="'
            . ProfileHtml::escape($href) . '">' . ProfileHtml::escape($label) . '</a>';
    }

    /** @param list<SearchHit> $hits */
    private static function results(
        BasePath $basePath,
        array $hits,
        GlobalDiscoveryRegistry $registry,
        string $selectedTab,
    ): string {
        if ($hits === []) {
            return '<div class="empty">Bu sekme ve filtrelerle erişebildiğiniz bir sonuç bulunamadı.</div>';
        }

        if ($selectedTab !== GlobalDiscoveryRegistry::ALL) {
            $category = $registry->find($selectedTab);
            $label = $category?->label ?? 'Sonuçlar';
            $body = '<section class="search-result-group"><h2>' . ProfileHtml::escape($label) . '</h2>';
            foreach ($hits as $hit) {
                $body .= self::hit($basePath, $hit, $registry);
            }
            return $body . '</section>';
        }

        /** @var array<string,list<SearchHit>> $groups */
        $groups = [];
        foreach ($hits as $hit) {
            $category = $registry->categoryForType($hit->documentType);
            if ($category !== null) {
                $groups[$category->key][] = $hit;
            }
        }

        $body = '';
        foreach ($registry->categories() as $category) {
            $group = $groups[$category->key] ?? [];
            if ($group === []) continue;
            $body .= '<section class="search-result-group"><h2>' . ProfileHtml::escape($category->label)
                . ' <small class="muted">(' . count($group) . ')</small></h2>';
            foreach ($group as $hit) {
                $body .= self::hit($basePath, $hit, $registry);
            }
            $body .= '</section>';
        }

        return $body === ''
            ? '<div class="empty">Erişebildiğiniz sınıflandırılmış bir sonuç bulunamadı.</div>'
            : $body;
    }

    private static function hit(
        BasePath $basePath,
        SearchHit $hit,
        GlobalDiscoveryRegistry $registry,
    ): string {
        $label = $hit->title ?? ($hit->documentType . ' #' . $hit->documentId);
        $title = ProfileHtml::escape($label);
        $type = ProfileHtml::escape(self::typeLabel($hit->documentType, $registry));
        $id = ProfileHtml::escape($hit->documentId);
        $heading = $title;
        if ($hit->documentType === 'user' && $hit->title !== null) {
            $href = ProfileHtml::escape($basePath->prepend('/members/' . rawurlencode($hit->title)));
            $heading = '<a href="' . $href . '">' . $title . '</a>';
        } elseif ($hit->documentType === 'faq.article') {
            $href = ProfileHtml::escape($basePath->prepend('/faq/articles/' . rawurlencode($hit->documentId)));
            $heading = '<a href="' . $href . '">' . $title . '</a>';
        }
        elseif ($hit->documentType === 'portfolio.item') {
            $href = ProfileHtml::escape($basePath->prepend('/portfolio/' . rawurlencode($hit->documentId)));
            $heading = '<a href="' . $href . '">' . $title . '</a>';
        } elseif ($hit->documentType === 'giveaway.item') {
            $href = ProfileHtml::escape($basePath->prepend('/giveaways/' . rawurlencode($hit->documentId)));
            $heading = '<a href="' . $href . '">' . $title . '</a>';
        } elseif ($hit->documentType === 'marketplace.listing') {
            $href = ProfileHtml::escape($basePath->prepend('/marketplace/listings/' . rawurlencode($hit->documentId)));
            $heading = '<a href="' . $href . '">' . $title . '</a>';
        }
        return '<article class="search-hit"><div class="search-hit-type">' . $type . '</div><h3>'
            . $heading . '</h3><div class="muted search-hit-id">' . $id . '</div></article>';
    }

    /** @param array<string,mixed> $query */
    private static function pagination(BasePath $basePath, array $query, int $page, bool $hasMore): string
    {
        if ($page <= 1 && !$hasMore) {
            return '';
        }

        $links = '';
        if ($page > 1) {
            $previous = $query;
            $previous['page'] = $page - 1;
            $href = $basePath->prepend('/search') . '?' . http_build_query($previous, '', '&', PHP_QUERY_RFC3986);
            $links .= '<a class="fx-btn" href="' . ProfileHtml::escape($href) . '">Önceki</a>';
        }
        if ($hasMore) {
            $next = $query;
            $next['page'] = $page + 1;
            $href = $basePath->prepend('/search') . '?' . http_build_query($next, '', '&', PHP_QUERY_RFC3986);
            $links .= '<a class="fx-btn fx-btn--primary" href="' . ProfileHtml::escape($href) . '">Sonraki</a>';
        }

        return '<nav class="surface-pagination search-pagination" aria-label="Arama sonucu sayfaları">'
            . $links . '</nav>';
    }

    private static function typeLabel(string $type, GlobalDiscoveryRegistry $registry): string
    {
        return match ($type) {
            'forum' => 'Forum',
            'thread' => 'Konu',
            'post' => 'Mesaj',
            'user' => 'Üye',
            'support.ticket' => 'Destek Talebi',
            'support.message' => 'Destek Mesajı',
            'faq.article' => 'SSS',
            'portfolio.item' => 'Portfolyo',
            'giveaway.item' => 'Çekiliş',
            'marketplace.listing' => 'Marketplace',
            default => $registry->categoryForType($type)?->label ?? $type,
        };
    }

    /** @param array<string,mixed> $query */
    private static function hasAdvancedFilters(array $query): bool
    {
        foreach (['type', 'forum', 'user', 'prefix', 'tag', 'state', 'thread_type', 'after', 'before', 'saved'] as $key) {
            if (is_string($query[$key] ?? null) && trim((string) $query[$key]) !== '') {
                return true;
            }
        }
        return false;
    }
}
