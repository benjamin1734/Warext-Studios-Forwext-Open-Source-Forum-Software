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

        return '<section class="appearance-guide">'
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
