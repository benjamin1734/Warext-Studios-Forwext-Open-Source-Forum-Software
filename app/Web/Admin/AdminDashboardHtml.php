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

        // Draw only measured values from the permission-filtered snapshot.
        // There is intentionally no demo, simulated or fabricated trend data.
        $sectionMaximum = max(1, ...array_map('count', array_values($snapshot->sections)));
        $sectionChart = '';
        foreach ($snapshot->sections as $sectionKey => $items) {
            $height = max(4, (int) round(100 * count($items) / $sectionMaximum));
            $sectionChart .= '<div class="acp-insight-column"><span class="acp-insight-value">'
                . count($items) . '</span><span class="acp-insight-bar" style="height:' . $height
                . '%"></span><small title="' . self::escape($snapshot->sectionLabel($sectionKey)) . '">'
                . self::escape($snapshot->sectionLabel($sectionKey)) . '</small></div>';
        }
        $queueMaximum = 1;
        foreach ($snapshot->actionQueues as $queue) {
            $queueMaximum = max($queueMaximum, $queue->count);
        }
        $queueChart = '';
        foreach ($snapshot->actionQueues as $queue) {
            $height = $queue->count === 0 ? 4 : max(4, (int) round(100 * $queue->count / $queueMaximum));
            $queueChart .= '<div class="acp-insight-column"><span class="acp-insight-value">'
                . $queue->count . '</span><span class="acp-insight-bar" style="height:' . $height
                . '%"></span><small title="' . self::escape($queue->label) . '">'
                . self::escape($queue->label) . '</small></div>';
        }
        $insights = '<section class="acp-insights" aria-label="Gerçek yönetim istatistikleri">'
            . '<header class="acp-insights-heading"><h2>İstatistikler</h2>'
            . '<span>Hesabına açık canlı yönetim verileri</span></header>'
            . '<div class="acp-insights-panels"><div class="acp-insight-panel"><h3>Yönetim alanları</h3>'
            . '<div class="acp-insight-chart" role="img" aria-label="Yetkili yönetim alanlarının kategori bazında sayıları">'
            . ($sectionChart !== '' ? $sectionChart : '<p>Gösterilecek yönetim alanı bulunmuyor.</p>')
            . '</div></div><div class="acp-insight-panel"><h3>İşlem kuyrukları</h3>'
            . '<div class="acp-insight-chart acp-insight-chart--queues" role="img" aria-label="Yetkili işlem kuyruklarının güncel sayıları">'
            . ($queueChart !== '' ? $queueChart : '<p>Bekleyen işlem kuyruğu bulunmuyor.</p>')
            . '</div></div></div></section>';

        $environment = '<section class="acp-environment" aria-label="Sunucu ortamı">'
            . '<header><h2>Sunucu ortamı</h2><span>Çalışan uygulama</span></header>'
            . '<div class="acp-environment-status"><strong>PHP ' . self::escape(PHP_VERSION) . '</strong>'
            . '<span>' . (version_compare(PHP_VERSION, '8.4.0', '>=')
                ? 'Forwext minimum PHP 8.4 sürümü karşılanıyor'
                : 'Forwext için PHP 8.4 veya üzeri gerekli')
            . '</span></div></section>';

        // All entry points derive from the permission-filtered dashboard snapshot.
        // These are section anchors: mutations and recent-navigation tracking stay
        // in the existing CSRF-protected directory buttons below.
        $launchpad = '<section class="acp-launchpad" aria-label="Yönetim kategorileri">'
            . '<div class="acp-launchpad-heading"><div><span class="acp-eyebrow">HIZLI GEZİNME</span>'
            . '<h2>Yönetim merkezleri</h2></div><p class="acp-muted">Yetkin olan bölümlere doğrudan ulaş.</p></div>'
            . '<div class="acp-launchpad-grid">';
        $launchIndex = 0;
        // Distinct line icons mirror the visual hierarchy of the supplied
        // dashboard reference; no icon font or third-party dependency.
        $tilePaths = [
            '<path d="M4 5h16v5H4zM4 14h7v6H4zM15 14h5v6h-5z"/>',
            '<path d="M8 4 6 8l-4 1v6l4 1 2 4h8l2-4 4-1V9l-4-1-2-4zM9 10h6v4H9z"/>',
            '<path d="M4 5h16v3H4zM4 11h16v3H4zM4 17h16v3H4z"/>',
            '<path d="M4 5h16v14H4zM9 5v14M4 10h16"/>',
            '<path d="M12 3 3 8v3h18V8l-9-5ZM6 13h3v7H6zM15 13h3v7h-3zM3 21h18"/>',
            '<path d="m12 2 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z"/>',
        ];
        foreach ($snapshot->sections as $sectionKey => $items) {
            $launchIndex++;
            $launchpad .= '<a class="acp-launchpad-tile" href="#acp-section-' . self::escape($sectionKey) . '">'
                . '<span class="acp-launchpad-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round">' . $tilePaths[($launchIndex - 1) % count($tilePaths)] . '</svg></span>'
                . '<span class="acp-launchpad-title">' . self::escape($snapshot->sectionLabel($sectionKey))
                . '<small>' . count($items) . ' yönetim alanı</small></span>'
                . '<span class="acp-launchpad-arrow" aria-hidden="true">↗</span></a>';
        }
        $launchpad .= '</div></section>';

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
            . $insights
            . $launchpad
            . $environment
            . '<div class="acp-dashboard-workspace">'
            . '<aside class="acp-dashboard-rail" aria-label="Administration bölümleri">'
            . '<h2 class="acp-dashboard-rail-title">Yönetim alanları</h2>'
            . $sectionNav
            . '</aside>'
            . '<div class="acp-dashboard-workspace-main">'
            . $searchResults
            . $queues
            . $favorites
            . $recent
            . $sections
            . '</div></div>'
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
