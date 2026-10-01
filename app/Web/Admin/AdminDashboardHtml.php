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
        $breadcrumbs = AdminBreadcrumbsHtml::render([
            ['label'=>'Admin', 'path'=>'/admin'],
            ['label'=>'Dashboard', 'path'=>null],
        ], $basePath);

        $favoriteKeys = [];
        foreach ($snapshot->favorites as $favorite) {
            $favoriteKeys[$favorite->key] = true;
        }

        $searchResults = '';
        if ($snapshot->search !== '') {
            $searchResults = '<section class="acp-panel"><div class="acp-heading"><div><h2>Arama sonuçları</h2>'
                . '<p class="acp-muted">Yalnız erişim yetkin olan yönetim alanları gösterilir.</p></div>'
                . '<span class="acp-count">' . count($snapshot->searchResults) . '</span></div>';
            if ($snapshot->searchResults === []) {
                $searchResults .= '<p class="acp-empty">“' . self::escape($snapshot->search)
                    . '” için erişilebilir bir yönetim alanı bulunamadı.</p>';
            } else {
                $searchResults .= '<div class="acp-grid">';
                foreach ($snapshot->searchResults as $item) {
                    $searchResults .= self::navigationCard(
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

        $queues = '<section class="acp-panel"><div class="acp-heading"><div><h2>İşlem gerekenler</h2>'
            . '<p class="acp-muted">Sayaçlar yalnız ilgili backend permission geçildiğinde sorgulanır.</p></div></div>';
        if ($snapshot->actionQueues === []) {
            $queues .= '<p class="acp-empty">Bu hesap için erişilebilir işlem kuyruğu bulunmuyor.</p>';
        } else {
            $queues .= '<div class="acp-queue-grid">';
            foreach ($snapshot->actionQueues as $queue) {
                $queues .= self::queueCard($queue, $action, $csrf);
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
            $sections .= '<section class="acp-panel"><div class="acp-heading"><div><h2>'
                . self::escape($snapshot->sectionLabel($sectionKey)) . '</h2>'
                . '<p class="acp-muted">Yetki kapsamına göre sadeleştirilmiş yönetim girişleri.</p></div>'
                . '<span class="acp-count">' . count($items) . '</span></div><div class="acp-grid">';
            foreach ($items as $item) {
                $sections .= self::navigationCard(
                    $item,
                    isset($favoriteKeys[$item->key]),
                    $action,
                    $csrf,
                    $snapshot->search,
                );
            }
            $sections .= '</div></section>';
        }

        return '<section class="acp-dashboard">'
            . $breadcrumbs
            . AdminUxQualityHtml::guidance(
                'Yönetim alanlarını tek giriş noktasından bul ve yalnız hesabının yetkili olduğu yüzeyleri aç.',
                'Global arama yalnız erişebildiğin kayıtlı ACP yüzeylerini tarar; favori ve son kullanılanlar kişisel navigasyon tercihidir.',
                'Aksiyon bekleyen kuyruklar ve arama sonuçları salt-okunur özet verir; gerçek değişiklik ilgili yetkili ekranda yapılır.',
                'Favoriler tekrar değiştirilebilir; yapılandırma değişiklikleri bu dashboard üzerinden doğrudan uygulanmaz.',
            )
            . '<header class="acp-hero"><div><h1>Administration</h1><p class="acp-muted">İhtiyacın olan yönetim alanını ara veya erişim yetkine göre sadeleştirilmiş bölümlerden seç.</p></div>'
            . '<form class="acp-search" method="get" action="' . $action . '">'
            . '<label class="sr-only" for="acp-search">Yönetim alanlarında ara</label>'
            . '<input id="acp-search" name="q" maxlength="80" value="' . self::escape($snapshot->search)
            . '" placeholder="Kullanıcı, tema, ödeme, destek, analytics…">'
            . '<button type="submit">Ara</button></form></header>'
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

        $html = '<section class="acp-panel"><div class="acp-heading"><div><h2>'
            . self::escape($title) . '</h2><p class="acp-muted">' . self::escape($description)
            . '</p></div><span class="acp-count">' . count($items) . '</span></div><div class="acp-grid">';
        foreach ($items as $item) {
            $html .= self::navigationCard(
                $item,
                isset($favoriteKeys[$item->key]),
                $action,
                $csrf,
                $search,
            );
        }

        return $html . '</div></section>';
    }

    private static function navigationCard(
        AdminNavigationItem $item,
        bool $favorite,
        string $action,
        string $csrf,
        string $search,
    ): string {
        return '<article class="acp-card"><div><small>'
            . self::escape($item->section->label()) . '</small><h3>' . self::escape($item->label)
            . '</h3></div><p>' . self::escape($item->description) . '</p><div class="acp-actions">'
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

    private static function queueCard(
        AdminActionQueueItem $queue,
        string $action,
        string $csrf,
    ): string {
        return '<article class="acp-queue"><div class="acp-queue-number">'
            . $queue->count . '</div><div><h3>' . self::escape($queue->label)
            . '</h3><p>' . self::escape($queue->description) . '</p>'
            . self::postButton($action, $csrf, 'open', $queue->navigationKey, 'Kuyruğu aç', 'acp-button primary')
            . '</div></article>';
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
