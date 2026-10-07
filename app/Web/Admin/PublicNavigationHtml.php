<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Admin\Navigation\ManagedPublicNavigationItem;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Navigation\NavigationAudience;
use Forwext\Core\Ui\Navigation\NavigationPlacement;

final class PublicNavigationHtml
{
    /** @param list<ManagedPublicNavigationItem> $items */
    public static function page(
        array $items,
        BasePath $basePath,
        string $csrf,
        ?string $updated = null,
        string $search = '',
        string $state = 'all',
        string $placement = 'all',
    ): string {
        $action = self::escape($basePath->prepend('/admin/navigation'));
        $search = trim($search);

        $total = count($items);
        $active = count(array_filter($items, static fn (ManagedPublicNavigationItem $item): bool => $item->enabled));
        $custom = count(array_filter($items, static fn (ManagedPublicNavigationItem $item): bool => $item->custom));
        $primary = count(array_filter(
            $items,
            static fn (ManagedPublicNavigationItem $item): bool => $item->placement === NavigationPlacement::Primary,
        ));

        $visible = array_values(array_filter(
            $items,
            static function (ManagedPublicNavigationItem $item) use ($search, $state, $placement): bool {
                if ($state === 'enabled' && !$item->enabled) {
                    return false;
                }
                if ($state === 'disabled' && $item->enabled) {
                    return false;
                }
                if ($placement !== 'all' && $item->placement->value !== $placement) {
                    return false;
                }
                if ($search === '') {
                    return true;
                }

                $haystack = strtolower(implode(' ', [
                    $item->key,
                    $item->label,
                    $item->path,
                    $item->audience->value,
                    $item->placement->value,
                    $item->custom ? 'custom özel' : 'system sistem',
                ]));

                return str_contains($haystack, strtolower($search));
            },
        ));

        $notice = match ($updated) {
            'saved' => '<div class="ops-notice">Navigasyon öğesi kaydedildi.</div>',
            'created' => '<div class="ops-notice">Özel navigasyon öğesi eklendi.</div>',
            'reset' => '<div class="ops-notice">Navigasyon öğesi varsayılana döndürüldü.</div>',
            'deleted' => '<div class="ops-notice">Özel navigasyon öğesi silindi.</div>',
            default => '',
        };

        $rows = '';
        foreach ($visible as $item) {
            $rows .= self::item($item, $action, $csrf);
        }
        if ($rows === '') {
            $rows = '<div class="nav-admin-empty">Bu filtrelerle eşleşen navigasyon öğesi bulunamadı.</div>';
        }

        $stateOptions = self::options(
            ['all'=>'Tüm durumlar','enabled'=>'Aktif','disabled'=>'Kapalı'],
            $state,
        );
        $placementOptions = self::options(
            ['all'=>'Tüm konumlar','primary'=>'Ana navigasyon','more'=>'Diğer menüsü'],
            $placement,
        );

        $filter = '<form class="nav-admin-filter platform-filter" method="get" action="' . $action . '">'
            . '<label>Ara<input name="q" maxlength="80" value="' . self::escape($search)
            . '" placeholder="Başlık, key veya yol"></label>'
            . '<label>Durum<select name="state">' . $stateOptions . '</select></label>'
            . '<label>Konum<select name="placement">' . $placementOptions . '</select></label>'
            . '<button class="acp-button primary" type="submit">Filtrele</button>'
            . '<a class="acp-button" href="' . $action . '">Sıfırla</a></form>';

        $overview = '<section class="platform-overview" aria-label="Navigasyon özeti">'
            . AdminPlatformNavHtml::stat('Toplam', $total, 'Yönetilebilir öğe')
            . AdminPlatformNavHtml::stat('Aktif', $active, 'Canlı navigasyonda')
            . AdminPlatformNavHtml::stat('Özel', $custom, 'Kullanıcı tanımlı')
            . AdminPlatformNavHtml::stat('Primary', $primary, 'Ana navigasyonda')
            . '</section>';

        return '<section class="nav-admin platform-workspace">'
            . AdminBreadcrumbsHtml::render([
                ['label'=>'Admin', 'path'=>'/admin'],
                ['label'=>'Platform', 'path'=>null],
                ['label'=>'Navigasyon', 'path'=>null],
            ], $basePath)
            . AdminPlatformNavHtml::render($basePath, 'navigation')
            . '<header class="platform-head nav-admin-head"><div><span class="platform-kicker">ACP PLATFORM</span>'
            . '<h1>Navigasyon Yönetimi</h1><p>Üst navigasyondaki bağlantıları, sıralamayı, görünürlüğü ve hedef kitleyi gerçek runtime yapılandırması üzerinden yönet.</p></div>'
            . '<a class="acp-button" href="' . self::escape($basePath->prepend('/')) . '">Siteyi görüntüle</a></header>'
            . $notice
            . $overview
            . $filter
            . AdminUxQualityHtml::guidance(
                'Başlık, key, yol, durum ve konum filtresiyle yönetilebilir navigasyonu daralt.',
                'Sistem bağlantılarını silmek yerine kapat veya varsayılana döndür; özel bağlantılar ayrı tutulur.',
                'Her satır canlı NavigationRuntime verisini düzenler; kaydetmeden önce hedef kitle ve placement görünür.',
                'Create/Save/Reset/Delete işlemleri server-side doğrulama, CSRF ve audit akışını kullanır.',
            )
            . '<section class="nav-admin-panel"><div class="nav-admin-section-head"><div><h2>Navigasyon öğeleri</h2>'
            . '<p>' . count($visible) . ' / ' . $total . ' öğe gösteriliyor. Değişiklikler sonraki request ile canlı navigasyona uygulanır.</p></div>'
            . '<span>' . count($visible) . ' sonuç</span></div>'
            . '<div class="nav-admin-list">' . $rows . '</div></section>'
            . '<section class="nav-admin-panel"><div class="nav-admin-section-head"><div><h2>Özel bağlantı ekle</h2>'
            . '<p>Forwext route’u veya aynı site içindeki güvenli bir yolu navigasyona ekle.</p></div></div>'
            . '<form class="nav-admin-create" method="post" action="' . $action . '">'
            . self::hidden($csrf, 'create')
            . '<label><span>Anahtar</span><div class="nav-admin-key-input"><code>custom.</code>'
            . '<input name="key_suffix" required maxlength="61" pattern="[a-z][a-z0-9-]{1,60}" placeholder="wiki"></div></label>'
            . '<label><span>Başlık</span><input name="label" required maxlength="80" placeholder="Wiki"></label>'
            . '<label><span>Yol</span><input name="path" required maxlength="191" value="/" placeholder="/wiki"></label>'
            . '<label><span>Sıra</span><input type="number" name="order" min="-10000" max="10000" value="500"></label>'
            . '<label><span>Hedef kitle</span>' . self::audienceSelect(NavigationAudience::Public) . '</label>'
            . '<label><span>Konum</span>' . self::placementSelect(NavigationPlacement::More) . '</label>'
            . '<button class="acp-button primary" type="submit">Bağlantı ekle</button>'
            . '</form></section></section>';
    }

    private static function item(
        ManagedPublicNavigationItem $item,
        string $action,
        string $csrf,
    ): string {
        $status = $item->enabled ? 'Aktif' : 'Kapalı';
        $kind = $item->custom ? 'Özel' : 'Sistem';
        $secondaryAction = $item->custom
            ? '<button class="nav-admin-danger" type="submit" name="action" value="delete">Sil</button>'
            : '<button class="nav-admin-secondary" type="submit" name="action" value="reset">Varsayılana dön</button>';

        return '<article class="nav-admin-row" data-enabled="' . ($item->enabled ? '1' : '0') . '">'
            . '<div class="nav-admin-row-meta"><div class="platform-row-title"><strong>' . self::escape($item->label) . '</strong>'
            . '<span class="platform-badge">' . self::escape($kind) . '</span><span class="platform-badge">'
            . self::escape($status) . '</span></div><code>' . self::escape($item->key) . '</code>'
            . '<small>' . self::escape($item->path) . ' · ' . self::escape($item->audience->value)
            . ' · ' . self::escape($item->placement->value) . '</small></div>'
            . '<form class="nav-admin-row-form" method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="key" value="' . self::escape($item->key) . '">'
            . '<label><span>Başlık</span><input name="label" required maxlength="80" value="' . self::escape($item->label) . '"></label>'
            . '<label class="nav-admin-path"><span>Yol</span><input name="path" required maxlength="191" value="' . self::escape($item->path) . '"></label>'
            . '<label><span>Sıra</span><input type="number" name="order" min="-10000" max="10000" value="' . $item->order . '"></label>'
            . '<label><span>Hedef kitle</span>' . self::audienceSelect($item->audience) . '</label>'
            . '<label><span>Konum</span>' . self::placementSelect($item->placement) . '</label>'
            . '<label class="nav-admin-enabled"><input type="checkbox" name="enabled" value="1"'
            . ($item->enabled ? ' checked' : '') . '><span>Aktif</span></label>'
            . '<div class="nav-admin-row-actions"><button class="acp-button primary" type="submit" name="action" value="save">Kaydet</button>'
            . $secondaryAction . '</div></form></article>';
    }

    private static function options(array $options, string $selected): string
    {
        $html = '';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . self::escape((string) $value) . '"'
                . ($selected === $value ? ' selected' : '') . '>' . self::escape((string) $label) . '</option>';
        }

        return $html;
    }

    private static function hidden(string $csrf, string $action): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="action" value="' . self::escape($action) . '">';
    }

    private static function audienceSelect(NavigationAudience $selected): string
    {
        $html = '<select name="audience">';
        foreach (NavigationAudience::cases() as $case) {
            $label = $case === NavigationAudience::Public ? 'Herkes' : 'Üyeler';
            $html .= '<option value="' . $case->value . '"' . ($case === $selected ? ' selected' : '') . '>'
                . $label . '</option>';
        }

        return $html . '</select>';
    }

    private static function placementSelect(NavigationPlacement $selected): string
    {
        $html = '<select name="placement">';
        foreach ([NavigationPlacement::Primary, NavigationPlacement::More] as $case) {
            $label = $case === NavigationPlacement::Primary ? 'Ana navigasyon' : 'Diğer menüsü';
            $html .= '<option value="' . $case->value . '"' . ($case === $selected ? ' selected' : '') . '>'
                . $label . '</option>';
        }

        return $html . '</select>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
