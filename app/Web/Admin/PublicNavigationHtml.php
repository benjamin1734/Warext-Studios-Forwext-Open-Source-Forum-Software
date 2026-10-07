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
    ): string {
        $action = self::escape($basePath->prepend('/admin/navigation'));
        $notice = match ($updated) {
            'saved' => '<div class="ops-notice">Navigasyon öğesi kaydedildi.</div>',
            'created' => '<div class="ops-notice">Özel navigasyon öğesi eklendi.</div>',
            'reset' => '<div class="ops-notice">Navigasyon öğesi varsayılana döndürüldü.</div>',
            'deleted' => '<div class="ops-notice">Özel navigasyon öğesi silindi.</div>',
            default => '',
        };

        $rows = '';
        foreach ($items as $item) {
            $rows .= self::item($item, $action, $csrf);
        }
        if ($rows === '') {
            $rows = '<div class="nav-admin-empty">Yönetilebilir navigasyon öğesi bulunamadı.</div>';
        }

        $enabled = 0;
        $custom = 0;
        $memberOnly = 0;
        $more = 0;
        foreach ($items as $item) {
            $enabled += $item->enabled ? 1 : 0;
            $custom += $item->custom ? 1 : 0;
            $memberOnly += $item->audience === NavigationAudience::Member ? 1 : 0;
            $more += $item->placement === NavigationPlacement::More ? 1 : 0;
        }
        $overview = '<section class="platform-admin-overview" aria-label="Navigasyon özeti">'
            . self::stat('Öğe', count($items), 'Yönetilebilir toplam')
            . self::stat('Aktif', $enabled, 'Kapalı ' . (count($items) - $enabled))
            . self::stat('Özel', $custom, 'Sistem ' . (count($items) - $custom))
            . self::stat('Diğer menüsü', $more, 'Üye özel ' . $memberOnly)
            . '</section>';

        return '<section class="nav-admin">'
            . AdminPlatformNavigationHtml::render($basePath, 'navigation')
            . '<header class="nav-admin-head"><div><h1>Navigasyon Yönetimi</h1>'
            . '<p>Üst navigasyondaki bağlantıları, sıralamayı, görünürlüğü ve hedef kitleyi yönet.</p></div>'
            . '<a class="acp-button" href="' . self::escape($basePath->prepend('/')) . '">Siteyi görüntüle</a></header>'
            . $notice
            . $overview
            . AdminUxQualityHtml::guidance(
                'Kullanıcının gördüğü ana ve Diğer navigasyon bağlantılarını düzenle.',
                'Sistem bağlantılarını silmek yerine kapat veya varsayılana döndür.',
                'Kaydetmeden sonra siteyi yeni sekmede kontrol et; yollar aynı origin içinde kalır.',
                'Sistem öğelerinde “Varsayılana dön”, özel öğelerde “Sil” kullan.',
            )
            . '<section class="nav-admin-panel"><div class="nav-admin-section-head"><div><h2>Navigasyon öğeleri</h2>'
            . '<p>Değişiklikler sonraki sayfa isteğinde canlı navigasyona uygulanır.</p></div>'
            . '<span>' . count($items) . ' öğe</span></div>'
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

    private static function stat(string $label, int $value, string $detail): string
    {
        return '<article class="platform-admin-stat"><span>' . self::escape($label) . '</span><strong>'
            . $value . '</strong><small>' . self::escape($detail) . '</small></article>';
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
            . '<div class="nav-admin-row-meta"><strong>' . self::escape($item->label) . '</strong>'
            . '<code>' . self::escape($item->key) . '</code>'
            . '<span>' . $kind . ' · ' . $status . '</span></div>'
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
            $html .= '<option value="' . $case->value . '"'
                . ($case === $selected ? ' selected' : '') . '>' . $label . '</option>';
        }

        return $html . '</select>';
    }

    private static function placementSelect(NavigationPlacement $selected): string
    {
        $html = '<select name="placement">';
        foreach ([NavigationPlacement::Primary, NavigationPlacement::More] as $case) {
            $label = $case === NavigationPlacement::Primary ? 'Ana navigasyon' : 'Diğer menüsü';
            $html .= '<option value="' . $case->value . '"'
                . ($case === $selected ? ' selected' : '') . '>' . $label . '</option>';
        }

        return $html . '</select>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
