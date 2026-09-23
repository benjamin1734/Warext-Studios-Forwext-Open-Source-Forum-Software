<?php

declare(strict_types=1);

namespace Forwext\App\Web\Appearance;

use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Appearance\Guide\AppearanceGuideItem;
use Forwext\Core\Ui\Appearance\Guide\AppearanceGuideLevel;
use Forwext\Core\Ui\Appearance\Guide\AppearanceGuideSnapshot;
use Forwext\Core\Ui\Appearance\Guide\AppearancePreset;
use Forwext\Core\Ui\Appearance\Guide\AppearancePreviewDevice;

final class AppearanceGuideHtml
{
    public static function page(AppearanceGuideSnapshot $snapshot, BasePath $basePath): string
    {
        $root = $basePath->prepend('/admin/appearance');
        $reset = self::url($root, [
            'mode' => AppearanceGuideLevel::Basic->value,
            'preset' => 'balanced',
            'device' => AppearancePreviewDevice::Desktop->value,
        ]);

        $modeLinks = self::modeLinks($snapshot, $root);
        $presetCards = self::presetCards($snapshot, $root);
        $deviceLinks = self::deviceLinks($snapshot, $root);
        $results = self::resultCards($snapshot, $basePath);
        $previewStyle = self::previewStyle($snapshot->selectedPreset);
        $themeUrl = self::escape($basePath->prepend('/admin/appearance/themes'));
        $layoutUrl = self::escape($basePath->prepend('/admin/appearance/layout'));
        $advancedStep = $snapshot->advancedAllowed
            ? '<li><strong>4. Yayınla veya geri al.</strong><span>Preview ve diff sonucunu doğruladıktan sonra gelişmiş yetkiyle publish/rollback kullan.</span></li>'
            : '<li><strong>4. Yayın yetkisini doğrula.</strong><span>Bu hesapta appearance.advanced yok. Staging hazırlayabilirsin; publish/rollback için yetkili bir yönetici gerekir.</span></li>';

        return '<section class="appearance-guide"><style>'
            . '.appearance-guide{display:grid;gap:18px}.ag-hero,.ag-section,.ag-preview,.ag-assistant{border:1px solid var(--line);background:var(--panel);border-radius:14px;padding:18px}'
            . '.ag-hero{display:grid;gap:10px}.ag-hero h1,.ag-section h2,.ag-preview h2,.ag-assistant h2{margin:0}.ag-muted{color:var(--muted)}'
            . '.ag-toolbar{display:flex;flex-wrap:wrap;gap:9px;align-items:center}.ag-toggle,.ag-device{display:flex;gap:6px;flex-wrap:wrap}.ag-link,.ag-chip,.ag-reset{display:inline-flex;align-items:center;min-height:40px;padding:8px 12px;border:1px solid var(--line);border-radius:9px;text-decoration:none;background:var(--panel2);color:var(--text)}'
            . '.ag-link[aria-current="page"],.ag-chip[aria-current="true"]{outline:2px solid var(--accent);outline-offset:1px}.ag-search{display:flex;gap:8px;flex:1;min-width:min(100%,280px)}.ag-search input{flex:1;min-width:0;border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);padding:9px 11px}.ag-search button{border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);padding:9px 13px;cursor:pointer}'
            . '.ag-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.ag-card{display:grid;gap:9px;border:1px solid var(--line);border-radius:12px;padding:14px;background:var(--panel2)}.ag-card h3{margin:0}.ag-card ul{margin:0;padding-left:20px}.ag-card.is-selected{outline:2px solid var(--accent);outline-offset:1px}.ag-card small{color:var(--muted)}'
            . '.ag-preview-frame{max-width:100%;margin-top:12px;transition:max-width .15s ease}.ag-preview-frame[data-device="tablet"]{max-width:760px}.ag-preview-frame[data-device="mobile"]{max-width:390px}.ag-preview-canvas{display:grid;grid-template-columns:minmax(0,1fr) var(--guide-sidebar-width);gap:var(--guide-gap);border:1px solid var(--line);border-radius:var(--guide-radius);padding:var(--guide-card-padding);background:var(--panel2)}.ag-preview-main,.ag-preview-side{display:grid;gap:var(--guide-gap)}.ag-preview-card{min-height:72px;border:1px solid var(--line);border-radius:var(--guide-radius);padding:var(--guide-card-padding);background:var(--panel)}.ag-preview-card h3{margin:0 0 6px;font-size:calc(1rem * var(--guide-heading-scale))}.ag-preview-lines{display:grid;gap:6px}.ag-preview-lines i{display:block;height:7px;border-radius:999px;background:var(--line)}.ag-preview-lines i:nth-child(2){width:82%}.ag-preview-lines i:nth-child(3){width:64%}'
            . '.ag-results{display:grid;gap:10px}.ag-result{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;border-top:1px solid var(--line);padding:13px 0}.ag-result:first-child{border-top:0}.ag-result h3{margin:0 0 4px}.ag-result p{margin:0}.ag-level{display:inline-flex;width:max-content;font-size:.82rem;border:1px solid var(--line);border-radius:999px;padding:3px 8px;margin-bottom:5px}.ag-locked{color:var(--muted);font-size:.9rem}.ag-assistant ol{display:grid;gap:10px;padding-left:22px}.ag-assistant li{padding-left:5px}.ag-assistant li span{display:block;color:var(--muted);margin-top:3px}.ag-actions{display:flex;gap:8px;flex-wrap:wrap}'
            . '@media(max-width:760px){.ag-preview-canvas{grid-template-columns:1fr}.ag-result{grid-template-columns:1fr}.ag-toolbar{align-items:stretch}.ag-search{order:3;flex-basis:100%}}'
            . '@media(prefers-reduced-motion:reduce){.ag-preview-frame{transition:none}}'
            . '</style>'
            . '<header class="ag-hero"><h1>Appearance Studio</h1>'
            . '<p class="ag-muted">Basit görünüm güvenli ve sık kullanılan ayarları öne çıkarır. Gelişmiş görünüm ayrıntılı revision, koşul ve yayınlama araçlarını ekler; görünüm değiştirmek hiçbir değeri sessizce değiştirmez.</p>'
            . '<div class="ag-toolbar">' . $modeLinks
            . '<form class="ag-search" method="get" action="' . self::escape($root) . '">'
            . '<input type="hidden" name="mode" value="' . self::escape($snapshot->mode->value) . '">'
            . '<input type="hidden" name="preset" value="' . self::escape($snapshot->selectedPreset->key) . '">'
            . '<input type="hidden" name="device" value="' . self::escape($snapshot->device->value) . '">'
            . '<label class="sr-only" for="appearance-search">Appearance ayarlarında ara</label>'
            . '<input id="appearance-search" name="q" maxlength="80" value="' . self::escape($snapshot->search) . '" placeholder="Tema, layout, CSS, mobil…">'
            . '<button type="submit">Ara</button></form>'
            . '<a class="ag-reset" href="' . self::escape($reset) . '">Varsayılana dön</a></div></header>'
            . '<section class="ag-section"><h2>Güvenli presetler</h2>'
            . '<p class="ag-muted">Preset seçimi canlı siteyi değiştirmez. Tam ve geçerli bir başlangıç profili üretir; aşağıdaki preview ve setup assistant bu profile göre ilerler. Kalıcı değişiklikler revision tabanlı tema/layout ekranlarında ayrıca kaydedilir.</p>'
            . '<div class="ag-grid">' . $presetCards . '</div></section>'
            . '<section class="ag-preview"><div class="ag-toolbar"><div><h2>Canlı preview</h2><p class="ag-muted">Preview izoledir; içerik yayınlamaz, izin değiştirmez ve bildirim göndermez.</p></div>'
            . $deviceLinks . '</div>'
            . '<div class="ag-preview-frame" data-device="' . self::escape($snapshot->device->value) . '" style="' . self::escape($previewStyle) . '">'
            . '<div class="ag-preview-canvas"><main class="ag-preview-main"><article class="ag-preview-card"><h3>Forum başlığı</h3><div class="ag-preview-lines"><i></i><i></i><i></i></div></article><article class="ag-preview-card"><h3>Konu kartı</h3><div class="ag-preview-lines"><i></i><i></i><i></i></div></article></main><aside class="ag-preview-side"><article class="ag-preview-card"><h3>Sidebar</h3><div class="ag-preview-lines"><i></i><i></i></div></article></aside></div></div></section>'
            . '<section class="ag-section"><h2>Ayarları bul</h2>'
            . '<p class="ag-muted">Arama yalnız sabit ayar metadatasında çalışır; korumalı tema/layout değerlerini arama sonucuna sızdırmaz.</p>'
            . '<div class="ag-results">' . $results . '</div></section>'
            . '<section class="ag-assistant"><h2>Kurulum asistanı</h2><ol>'
            . '<li><strong>1. Başlangıç profilini seç.</strong><span>Şu an ' . self::escape($snapshot->selectedPreset->label) . ' presetini önizliyorsun. Preset ilgisiz ayarları ezmez.</span></li>'
            . '<li><strong>2. Tema staging revision’ını hazırla.</strong><span>Şablon, dil ve görünüm değişikliklerini canlıya almadan kaydet.</span><div class="ag-actions"><a class="ag-link" href="' . $themeUrl . '">Tema yönetimine git</a></div></li>'
            . '<li><strong>3. Layout taslağını düzenle.</strong><span>Widget slotlarını, cihaz ve audience koşullarını ayrı taslakta kontrol et.</span><div class="ag-actions"><a class="ag-link" href="' . $layoutUrl . '">Layout Builder’a git</a></div></li>'
            . $advancedStep
            . '</ol><p class="ag-muted">Geri dönüş: tema revision geçmişindeki rollback ve layout draft/published ayrımı canlı durumu korur. Bu rehberin kendi görünümünü sıfırlamak için “Varsayılana dön” bağlantısını kullan.</p></section>'
            . '</section>';
    }

    private static function modeLinks(AppearanceGuideSnapshot $snapshot, string $root): string
    {
        $html = '<nav class="ag-toggle" aria-label="Appearance görünüm modu">';
        foreach (AppearanceGuideLevel::cases() as $mode) {
            $url = self::url($root, [
                'mode' => $mode->value,
                'preset' => $snapshot->selectedPreset->key,
                'device' => $snapshot->device->value,
                'q' => $snapshot->search,
            ]);
            $label = $mode === AppearanceGuideLevel::Basic ? 'Basit' : 'Gelişmiş';
            $html .= '<a class="ag-link" href="' . self::escape($url) . '"'
                . ($snapshot->mode === $mode ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        }

        return $html . '</nav>';
    }

    private static function presetCards(AppearanceGuideSnapshot $snapshot, string $root): string
    {
        $html = '';
        foreach ($snapshot->presets as $preset) {
            $url = self::url($root, [
                'mode' => $snapshot->mode->value,
                'preset' => $preset->key,
                'device' => $snapshot->device->value,
                'q' => $snapshot->search,
            ]);
            $changes = '';
            foreach ($preset->changes as $change) {
                $changes .= '<li>' . self::escape($change) . '</li>';
            }
            $selected = $snapshot->selectedPreset->key === $preset->key;

            $html .= '<article class="ag-card' . ($selected ? ' is-selected' : '') . '"><h3>'
                . self::escape($preset->label) . '</h3><p>' . self::escape($preset->description) . '</p>'
                . '<ul>' . $changes . '</ul><a class="ag-link" href="' . self::escape($url) . '"'
                . ($selected ? ' aria-current="page"' : '') . '>'
                . ($selected ? 'Seçili preset' : 'Preset’i önizle') . '</a></article>';
        }

        return $html;
    }

    private static function deviceLinks(AppearanceGuideSnapshot $snapshot, string $root): string
    {
        $html = '<nav class="ag-device" aria-label="Preview cihazı">';
        foreach (AppearancePreviewDevice::cases() as $device) {
            $url = self::url($root, [
                'mode' => $snapshot->mode->value,
                'preset' => $snapshot->selectedPreset->key,
                'device' => $device->value,
                'q' => $snapshot->search,
            ]);
            $label = match ($device) {
                AppearancePreviewDevice::Desktop => 'Desktop',
                AppearancePreviewDevice::Tablet => 'Tablet',
                AppearancePreviewDevice::Mobile => 'Mobil',
            };
            $html .= '<a class="ag-chip" href="' . self::escape($url) . '"'
                . ($snapshot->device === $device ? ' aria-current="true"' : '') . '>'
                . $label . '</a>';
        }

        return $html . '</nav>';
    }

    private static function resultCards(AppearanceGuideSnapshot $snapshot, BasePath $basePath): string
    {
        if ($snapshot->items === []) {
            return '<p class="ag-muted">Bu filtreyle eşleşen appearance ayarı bulunamadı.</p>';
        }

        $html = '';
        foreach ($snapshot->items as $item) {
            $accessible = $snapshot->isAccessible($item);
            $level = $item->level === AppearanceGuideLevel::Basic ? 'Basit' : 'Gelişmiş';
            $action = $accessible
                ? '<a class="ag-link" href="' . self::escape($basePath->prepend($item->path)) . '">Aç</a>'
                : '<span class="ag-locked">' . self::escape($item->requiredPermission) . ' yetkisi gerekli</span>';

            $html .= '<article class="ag-result"><div><span class="ag-level">' . $level . '</span><h3>'
                . self::escape($item->label) . '</h3><p>' . self::escape($item->description) . '</p>'
                . '<small class="ag-muted">Geri dönüş: ' . self::escape($item->recoveryHint) . '</small></div>'
                . '<div>' . $action . '</div></article>';
        }

        return $html;
    }

    private static function previewStyle(AppearancePreset $preset): string
    {
        $parts = [];
        foreach ($preset->previewVariables as $key => $value) {
            $parts[] = $key . ':' . $value;
        }

        return implode(';', $parts);
    }

    /** @param array<string,string> $query */
    private static function url(string $root, array $query): string
    {
        $filtered = array_filter($query, static fn (string $value): bool => $value !== '');

        return $root . ($filtered === [] ? '' : '?' . http_build_query($filtered, '', '&', PHP_QUERY_RFC3986));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
