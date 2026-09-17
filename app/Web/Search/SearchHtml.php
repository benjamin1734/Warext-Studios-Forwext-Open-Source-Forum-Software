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
    ): string {
        $discovery ??= GlobalDiscoveryRegistry::withCoreDefaults();
        $action = ProfileHtml::escape($basePath->prepend('/search'));
        $value = static fn (string $key): string => ProfileHtml::escape(
            is_string($query[$key] ?? null) ? (string) $query[$key] : '',
        );
        $advancedOpen = self::hasAdvancedFilters($query) ? ' open' : '';

        $content = '<section class="card"><div class="search-head"><div><h1>Keşfet ve Ara</h1>'
            . '<p class="muted">Erişebildiğiniz içerikleri türüne göre tek yerden arayın.</p></div></div>'
            . self::tabs($basePath, $query, $discovery, $selectedTab)
            . '<form class="search-form" method="get" action="' . $action . '">'
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
            . '">Filtreleri temizle</a></div></form>';

        if ($error !== null) {
            $content .= '<div class="search-alert" role="alert">' . ProfileHtml::escape($error) . '</div>';
        } elseif ($text !== '') {
            $content .= '<div class="search-results"><div class="search-result-head"><h2>Sonuçlar</h2>'
                . '<span class="muted">Sayfa ' . $page . '</span></div>'
                . self::results($basePath, $hits, $discovery, $selectedTab)
                . '</div>';
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
        $tabs = '<nav class="tabs" aria-label="İçerik türleri">'
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
            $body = '<section class="section"><h2>' . ProfileHtml::escape($label) . '</h2>';
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
            $body .= '<section class="section"><h2>' . ProfileHtml::escape($category->label)
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
        }
        return '<article class="search-hit"><div class="search-hit-type">' . $type . '</div><h3>'
            . $heading . '</h3><div class="muted search-hit-id">' . $id . '</div></article>';
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
