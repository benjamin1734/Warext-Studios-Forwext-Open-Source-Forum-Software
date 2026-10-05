<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Admin\Dashboard\AdminActionQueueItem;
use Forwext\Core\Admin\Dashboard\AdminDashboardSnapshot;
use Forwext\Core\Admin\Navigation\AdminNavigationItem;
use Forwext\Core\Routing\BasePath;

final class AdminDashboardHtml
{
    public static function page(
        AdminDashboardSnapshot $snapshot,
        BasePath $basePath,
        string $csrf,
    ): string {
        $action = self::escape($basePath->prepend('/admin'));
        $dashboardUrl = self::escape($basePath->prepend('/admin'));
        $breadcrumbs = AdminBreadcrumbsHtml::render([
            ['label'=>'Admin', 'path'=>'/admin'],
            ['label'=>'Dashboard', 'path'=>null],
        ], $basePath);

        $favoriteKeys = [];
        foreach ($snapshot->favorites as $favorite) {
            $favoriteKeys[$favorite->key] = true;
        }

        $accessibleCount = 0;
        foreach ($snapshot->sections as $items) {
            $accessibleCount += count($items);
        }
        $queueTotal = 0;
        foreach ($snapshot->actionQueues as $queue) {
            $queueTotal += $queue->count;
        }

        $overview = '<section class="acp-overview" aria-label="Administration özeti">'
            . self::overviewStat('Erişilebilir alan', $accessibleCount, 'Permission filtresinden geçen yönetim hedefleri')
            . self::overviewStat('İşlem bekleyen', $queueTotal, 'Yetkili olduğun operasyon kuyrukları')
            . self::overviewStat('Favori', count($snapshot->favorites), 'Kişisel hızlı erişim')
            . self::overviewStat('Son kullanılan', count($snapshot->recent), 'Gerçek POST açılışlarından oluşur')
            . '</section>';

        $sectionNav = '<nav class="acp-section-index" aria-label="Administration bölümleri">';
        foreach ($snapshot->sections as $sectionKey => $items) {
            $sectionNav .= '<a href="#acp-section-' . self::escape($sectionKey) . '"><span>'
                . self::escape($snapshot->sectionLabel($sectionKey)) . '</span><strong>' . count($items) . '</strong></a>';
        }
        $sectionNav .= '</nav>';

        $searchResults = '';
        if ($snapshot->search !== '') {
            $searchResults = '<section class="acp-panel acp-search-results"><div class="acp-heading"><div><h2>Arama sonuçları</h2>'
                . '<p class="acp-muted">Yalnız erişim yetkin olan yönetim alanları gösterilir.</p></div>'
                . '<div class="acp-heading-actions"><span class="acp-count">' . count($snapshot->searchResults) . '</span>'
                . '<a class="acp-button" href="' . $dashboardUrl . '">Aramayı temizle</a></div></div>';
            if ($snapshot->searchResults === []) {
                $searchResults .= '<p class="acp-empty">“' . self::escape($snapshot->search)
                    . '” için erişilebilir bir yönetim alanı bulunamadı.</p>';
            } else {
                $searchResults .= '<div class="acp-directory">';
                foreach ($snapshot->searchResults as $item) {
                    $searchResults .= self::navigationRow(
                        $item,
                        isset($favoriteKeys[$item->key]),
                        $action,
                        $csrf,
                        $snapshot->search,
                    );
                }
                $searchResults .= '</div>';
            }
            $searchResults .= '</section>';
        }

        $queues = '<section class="acp-panel acp-queue-panel"><div class="acp-heading"><div><h2>İşlem gerekenler</h2>'
            . '<p class="acp-muted">Sayaçlar yalnız ilgili backend permission geçildiğinde sorgulanır.</p></div>'
            . '<span class="acp-count">' . $queueTotal . '</span></div>';
        if ($snapshot->actionQueues === []) {
            $queues .= '<p class="acp-empty">Bu hesap için erişilebilir işlem kuyruğu bulunmuyor.</p>';
        } else {
            $queues .= '<div class="acp-queue-strip">';
            foreach ($snapshot->actionQueues as $queue) {
                $queues .= self::queueRow($queue, $action, $csrf);
            }
            $queues .= '</div>';
        }
        $queues .= '</section>';

        $favorites = self::collectionSection(
            'Favoriler',
            'Sık kullandığın yönetim alanları. Erişim kaybedilen alanlar otomatik olarak görünmez.',
            $snapshot->favorites,
            $favoriteKeys,
            $action,
            $csrf,
            $snapshot->search,
        );
        $recent = self::collectionSection(
            'Son kullanılanlar',
            'Yalnız POST + CSRF ile gerçekten açtığın yönetim alanlarının son listesi.',
            $snapshot->recent,
            $favoriteKeys,
            $action,
            $csrf,
            $snapshot->search,
        );

        $sections = '';
        foreach ($snapshot->sections as $sectionKey => $items) {
            $sections .= '<section class="acp-panel acp-directory-section" id="acp-section-' . self::escape($sectionKey) . '">'
                . '<div class="acp-heading"><div><h2>' . self::escape($snapshot->sectionLabel($sectionKey)) . '</h2>'
                . '<p class="acp-muted">Yetki kapsamına göre sadeleştirilmiş yönetim girişleri.</p></div>'
                . '<span class="acp-count">' . count($items) . '</span></div><div class="acp-directory">';
            foreach ($items as $item) {
                $sections .= self::navigationRow(
                    $item,
                    isset($favoriteKeys[$item->key]),
                    $action,
                    $csrf,
                    $snapshot->search,
                );
            }
            $sections .= '</div></section>';
        }

        return '<section class="acp-dashboard acp-dashboard--dense">'
            . $breadcrumbs
            . AdminUxQualityHtml::guidance(
                'Yönetim alanlarını tek giriş noktasından bul ve yalnız hesabının yetkili olduğu yüzeyleri aç.',
                'Global arama yalnız erişebildiğin kayıtlı ACP yüzeylerini tarar; favori ve son kullanılanlar kişisel navigasyon tercihidir.',
                'Aksiyon bekleyen kuyruklar ve arama sonuçları salt-okunur özet verir; gerçek değişiklik ilgili yetkili ekranda yapılır.',
                'Favoriler tekrar değiştirilebilir; yapılandırma değişiklikleri bu dashboard üzerinden doğrudan uygulanmaz.',
            )
            . '<header class="acp-hero"><div><span class="acp-eyebrow">ADMINISTRATION CONTROL PANEL</span>'
            . '<h1>Administration</h1><p class="acp-muted">İhtiyacın olan yönetim alanını ara veya erişim yetkine göre sadeleştirilmiş bölümlerden seç.</p></div>'
            . '<form class="acp-search" method="get" action="' . $action . '">'
            . '<label class="sr-only" for="acp-search">Yönetim alanlarında ara</label>'
            . '<input id="acp-search" name="q" maxlength="80" value="' . self::escape($snapshot->search)
            . '" placeholder="Kullanıcı, tema, ödeme, destek, analytics…">'
            . '<button type="submit">Ara</button></form></header>'
            . $overview
            . $sectionNav
            . $searchResults
            . $queues
            . $favorites
            . $recent
            . $sections
            . '</section>';
    }

    /**
     * @param list<AdminNavigationItem> $items
     * @param array<string,bool> $favoriteKeys
     */
    private static function collectionSection(
        string $title,
        string $description,
        array $items,
        array $favoriteKeys,
        string $action,
        string $csrf,
        string $search,
    ): string {
        if ($items === []) {
            return '';
        }

        $html = '<section class="acp-panel acp-collection"><div class="acp-heading"><div><h2>'
            . self::escape($title) . '</h2><p class="acp-muted">' . self::escape($description)
            . '</p></div><span class="acp-count">' . count($items) . '</span></div><div class="acp-directory">';
        foreach ($items as $item) {
            $html .= self::navigationRow(
                $item,
                isset($favoriteKeys[$item->key]),
                $action,
                $csrf,
                $search,
            );
        }

        return $html . '</div></section>';
    }

    private static function navigationRow(
        AdminNavigationItem $item,
        bool $favorite,
        string $action,
        string $csrf,
        string $search,
    ): string {
        return '<article class="acp-directory-row"><div class="acp-directory-main"><div class="acp-directory-kicker">'
            . '<span>' . self::escape($item->section->label()) . '</span><code>' . self::escape($item->path) . '</code></div>'
            . '<h3>' . self::escape($item->label) . '</h3><p>' . self::escape($item->description) . '</p></div>'
            . '<div class="acp-directory-actions">'
            . self::postButton($action, $csrf, 'open', $item->key, 'Aç', 'acp-button primary')
            . self::postButton(
                $action,
                $csrf,
                'toggle_favorite',
                $item->key,
                $favorite ? 'Favoriden çıkar' : 'Favoriye ekle',
                'acp-button favorite',
                $search,
                $favorite,
            )
            . '</div></article>';
    }

    private static function queueRow(
        AdminActionQueueItem $queue,
        string $action,
        string $csrf,
    ): string {
        return '<article class="acp-queue-row"><div class="acp-queue-number">' . $queue->count . '</div>'
            . '<div class="acp-queue-copy"><h3>' . self::escape($queue->label) . '</h3><p>'
            . self::escape($queue->description) . '</p></div>'
            . '<div class="acp-queue-action">'
            . self::postButton($action, $csrf, 'open', $queue->navigationKey, 'Aç', 'acp-button primary')
            . '</div></article>';
    }

    private static function overviewStat(string $label, int $value, string $description): string
    {
        return '<article class="acp-overview-stat"><span>' . self::escape($label) . '</span><strong>'
            . $value . '</strong><small>' . self::escape($description) . '</small></article>';
    }

    private static function postButton(
        string $action,
        string $csrf,
        string $operation,
        string $navigationKey,
        string $label,
        string $class,
        string $search = '',
        ?bool $pressed = null,
    ): string {
        return '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="action" value="' . self::escape($operation) . '">'
            . '<input type="hidden" name="navigation_key" value="' . self::escape($navigationKey) . '">'
            . ($search !== '' ? '<input type="hidden" name="search" value="' . self::escape($search) . '">' : '')
            . '<button class="' . self::escape($class) . '" type="submit"'
            . ($pressed !== null ? ' aria-pressed="' . ($pressed ? 'true' : 'false') . '"' : '')
            . '>' . self::escape($label) . '</button></form>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
