<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Admin\Integration\IntegrationSecretDefinition;
use Forwext\Core\Admin\Integration\IntegrationSection;
use Forwext\Core\Admin\Integration\IntegrationSettingDefinition;
use Forwext\Core\Admin\Integration\IntegrationSettingType;
use Forwext\Core\Admin\Integration\SystemIntegrationSnapshot;
use Forwext\Core\Capability\CapabilityEntry;
use Forwext\Core\Routing\BasePath;

final class SystemIntegrationHtml
{
    public static function page(
        SystemIntegrationSnapshot $snapshot,
        BasePath $basePath,
        string $csrf,
        string $search,
        ?IntegrationSection $selectedSection,
        ?string $updated,
    ): string {
        $action = self::escape($basePath->prepend('/admin/integrations'));
        $breadcrumbs = AdminBreadcrumbsHtml::render([
            ['label'=>'Admin', 'path'=>'/admin'],
            ['label'=>'Sistem ve Entegrasyonlar', 'path'=>null],
        ], $basePath);

        $notice = match ($updated) {
            'setting' => '<div class="int-notice">Ayar kaydedildi. PHP web isteklerinde yeni değer sonraki request ile yüklenir.</div>',
            'reset' => '<div class="int-notice">Generated override kaldırıldı; değer default/environment katmanına döndü.</div>',
            'secret' => '<div class="int-notice">Secret encrypted store içinde güncellendi. Secret değeri bu ekranda geri okunmaz.</div>',
            'secret-delete' => '<div class="int-notice">Secret encrypted store içinden silindi.</div>',
            default => '',
        };

        $sectionNav = '<a class="int-chip" href="' . $action . '"'
            . ($selectedSection === null ? ' aria-current="page"' : '') . '>Tümü</a>';
        foreach (IntegrationSection::cases() as $section) {
            $url = $action . '?section=' . rawurlencode($section->value);
            if ($search !== '') {
                $url .= '&q=' . rawurlencode($search);
            }
            $sectionNav .= '<a class="int-chip" href="' . $url . '"'
                . ($selectedSection === $section ? ' aria-current="page"' : '') . '>'
                . self::escape($section->label()) . '</a>';
        }

        $sectionsHtml = '';
        $visibleCount = 0;
        foreach (IntegrationSection::cases() as $section) {
            if ($selectedSection !== null && $selectedSection !== $section) {
                continue;
            }

            $settings = array_values(array_filter(
                $snapshot->settings,
                static fn (IntegrationSettingDefinition $definition): bool =>
                    $definition->section === $section && self::matchesSetting($definition, $search),
            ));
            $secrets = array_values(array_filter(
                $snapshot->secrets,
                static fn (IntegrationSecretDefinition $definition): bool =>
                    $definition->section === $section && self::matchesSecret($definition, $search),
            ));
            if ($settings === [] && $secrets === []) {
                continue;
            }

            $visibleCount += count($settings) + count($secrets);
            $cards = '';
            foreach ($settings as $definition) {
                $cards .= self::settingCard($definition, $snapshot, $action, $csrf, $selectedSection);
            }
            foreach ($secrets as $definition) {
                $cards .= self::secretCard($definition, $snapshot, $action, $csrf, $selectedSection);
            }

            $sectionsHtml .= '<section class="int-panel"><div class="int-heading"><div><h2>'
                . self::escape($section->label()) . '</h2><p class="int-muted">'
                . self::escape($section->description()) . '</p></div><span class="int-count">'
                . (count($settings) + count($secrets)) . '</span></div><div class="int-grid">'
                . $cards . '</div></section>';
        }

        if ($sectionsHtml === '') {
            $sectionsHtml = '<section class="int-panel"><p class="int-empty">Bu filtreyle eşleşen entegrasyon ayarı bulunamadı.</p></section>';
        }

        $capabilities = '';
        foreach ($snapshot->capabilities as $capability) {
            $capabilities .= self::capability($capability);
        }

        return '<section class="integration-admin"><style>'
            . '.integration-admin{display:grid;gap:18px}.int-hero,.int-panel{border:1px solid var(--line);background:var(--panel);border-radius:14px;padding:18px}'
            . '.int-hero{display:grid;gap:12px}.int-hero h1,.int-panel h2,.int-card h3{margin:0}.int-muted,.int-empty{color:var(--muted)}'
            . '.int-badges,.int-actions,.int-section-nav{display:flex;flex-wrap:wrap;gap:7px}.int-badge,.int-chip{display:inline-flex;align-items:center;min-height:30px;padding:4px 9px;border:1px solid var(--line);border-radius:999px;background:var(--panel2);color:var(--text);text-decoration:none;font-size:.86rem}'
            . '.int-chip[aria-current="page"]{outline:2px solid var(--accent);outline-offset:1px}.int-notice{border:1px solid var(--line);border-radius:10px;padding:11px 13px;background:var(--panel2)}'
            . '.int-search{display:grid;grid-template-columns:minmax(0,1fr) minmax(160px,230px) auto;gap:8px}.int-search input,.int-search select,.int-control{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);padding:9px 10px}'
            . '.int-search button,.int-button{border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);padding:9px 12px;cursor:pointer;text-decoration:none}.int-button.primary{font-weight:700}'
            . '.int-heading{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}.int-heading p{margin:5px 0 0}.int-count{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;border:1px solid var(--line);border-radius:999px;background:var(--panel2);font-weight:700}'
            . '.int-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.int-card{display:grid;gap:10px;border:1px solid var(--line);border-radius:12px;padding:14px;background:var(--panel2)}.int-card p{margin:0}.int-card form{display:grid;gap:8px;margin:0}.int-card label{font-weight:700}.int-meta{font-size:.82rem;color:var(--muted);word-break:break-word}.int-current{border:1px dashed var(--line);border-radius:8px;padding:8px 10px;background:var(--panel);word-break:break-word;white-space:pre-wrap}'
            . '.int-secret-status{font-weight:700}.int-danger{border-style:dashed}.int-capabilities{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px}.int-capability{display:flex;gap:8px;align-items:flex-start;border:1px solid var(--line);border-radius:10px;padding:10px;background:var(--panel2)}.int-dot{width:10px;height:10px;border-radius:50%;margin-top:5px;background:currentColor}.int-capability[data-ok="0"]{opacity:.7}'
            . '.acp-breadcrumbs ol{display:flex;flex-wrap:wrap;gap:7px;list-style:none;padding:0;margin:0;color:var(--muted)}.acp-breadcrumbs li+li:before{content:"/";margin-right:7px}.acp-breadcrumbs a{color:inherit}'
            . '@media(max-width:720px){.int-search{grid-template-columns:1fr}.int-heading{align-items:center}.int-grid{grid-template-columns:1fr}.int-actions{display:grid}.int-actions .int-button{width:100%}}'
            . '</style>'
            . $breadcrumbs
            . '<header class="int-hero"><div><h1>Sistem ve Entegrasyonlar</h1>'
            . '<p class="int-muted">Mail, OAuth, Turnstile, AI, storage, cache, queue, search, realtime ve API/webhook hazırlığını tek yerde yönet. Secret değerleri hiçbir zaman ekrana geri basılmaz.</p></div>'
            . $notice
            . '<form class="int-search" method="get" action="' . $action . '">'
            . '<label class="sr-only" for="integration-search">Entegrasyon ayarlarında ara</label>'
            . '<input id="integration-search" name="q" maxlength="80" value="' . self::escape($search)
            . '" placeholder="SMTP, OAuth, Redis, Turnstile, AI…">'
            . '<select name="section" aria-label="Entegrasyon bölümü"><option value="all">Tüm bölümler</option>'
            . self::sectionOptions($selectedSection)
            . '</select><button type="submit">Filtrele</button></form>'
            . '<nav class="int-section-nav" aria-label="Entegrasyon bölümleri">' . $sectionNav . '</nav>'
            . '<p class="int-muted">Environment override varsa environment değeri kazanır. Web runtime yeni generated ayarı sonraki request’te yükler; uzun yaşayan worker/gateway süreçleri yeniden yükleme gerektirebilir.</p>'
            . '</header>'
            . '<section class="int-panel"><div class="int-heading"><div><h2>Görünen yapılandırma</h2><p class="int-muted">'
            . $visibleCount . ' ayar/secret girişi gösteriliyor. Reset yalnız generated override’ı kaldırır; default veya environment değerini silmez.</p></div></div></section>'
            . $sectionsHtml
            . '<section class="int-panel"><div class="int-heading"><div><h2>Runtime capabilities</h2>'
            . '<p class="int-muted">Minimum cPanel profilini ve advanced runtime seçeneklerini teşhis etmek için salt-okunur capability görünümü.</p></div></div>'
            . '<div class="int-capabilities">' . $capabilities . '</div></section>'
            . '</section>';
    }

    private static function settingCard(
        IntegrationSettingDefinition $definition,
        SystemIntegrationSnapshot $snapshot,
        string $action,
        string $csrf,
        ?IntegrationSection $selectedSection,
    ): string {
        $value = $snapshot->values[$definition->key] ?? null;
        $environment = $snapshot->environmentOverrides[$definition->key] ?? false;
        $badges = '<span class="int-badge">' . self::escape($definition->type->value) . '</span>';
        if ($environment) {
            $badges .= '<span class="int-badge">environment override</span>';
        }
        if (!$definition->editable) {
            $badges .= '<span class="int-badge">read-only</span>';
        }

        $forms = '';
        if ($definition->editable) {
            $forms .= '<form method="post" action="' . $action . '">'
                . self::hidden($csrf, 'save_setting', $definition->key, $selectedSection)
                . '<label for="' . self::escape('setting-' . $definition->key) . '">Yeni değer</label>'
                . self::control($definition, $value)
                . '<button class="int-button primary" type="submit">Kaydet</button></form>';
        }

        $forms .= '<form method="post" action="' . $action . '">'
            . self::hidden($csrf, 'reset_setting', $definition->key, $selectedSection)
            . '<button class="int-button" type="submit">Generated override’ı kaldır</button></form>';

        $note = $definition->availabilityNote !== null
            ? '<p class="int-meta">' . self::escape($definition->availabilityNote) . '</p>'
            : '';

        return '<article class="int-card"><div class="int-badges">' . $badges . '</div><h3>'
            . self::escape($definition->label) . '</h3><p>' . self::escape($definition->description)
            . '</p><div class="int-current"><strong>Etkin değer:</strong> '
            . self::escape(self::displayValue($value)) . '</div><div class="int-meta">'
            . self::escape($definition->key . ' · ' . $definition->configPath) . '</div>'
            . ($environment ? '<p class="int-meta">Bu alanı kaydetmek generated değeri hazırlar; environment değişkeni kaldırılana kadar etkin değer environment’dan gelir.</p>' : '')
            . $note . '<div class="int-actions">' . $forms . '</div></article>';
    }

    private static function secretCard(
        IntegrationSecretDefinition $definition,
        SystemIntegrationSnapshot $snapshot,
        string $action,
        string $csrf,
        ?IntegrationSection $selectedSection,
    ): string {
        $configured = $snapshot->secretConfigured[$definition->key] ?? false;
        $delete = '';
        if ($configured) {
            $delete = '<form class="int-danger" method="post" action="' . $action . '">'
                . self::hidden($csrf, 'delete_secret', $definition->key, $selectedSection)
                . '<label for="' . self::escape('confirm-' . $definition->key) . '">Silmek için key’i aynen yaz</label>'
                . '<input class="int-control" id="' . self::escape('confirm-' . $definition->key)
                . '" name="confirm_key" maxlength="96" autocomplete="off" placeholder="'
                . self::escape($definition->key) . '">'
                . '<button class="int-button" type="submit">Secret’ı sil</button></form>';
        }

        return '<article class="int-card"><div class="int-badges"><span class="int-badge">secret</span>'
            . '<span class="int-badge">' . ($configured ? 'configured' : 'not configured') . '</span></div><h3>'
            . self::escape($definition->label) . '</h3><p>' . self::escape($definition->description)
            . '</p><p class="int-secret-status">Değer: ' . ($configured ? '••••••••' : 'ayarlanmamış') . '</p>'
            . '<div class="int-meta">' . self::escape($definition->key) . '</div>'
            . '<form method="post" action="' . $action . '">'
            . self::hidden($csrf, 'set_secret', $definition->key, $selectedSection)
            . '<label for="' . self::escape('secret-' . $definition->key) . '">'
            . ($configured ? 'Secret’ı değiştir' : 'Secret belirle') . '</label>'
            . '<input class="int-control" id="' . self::escape('secret-' . $definition->key)
            . '" type="password" name="secret_value" maxlength="' . $definition->maxLength
            . '" autocomplete="new-password" value="">'
            . '<button class="int-button primary" type="submit">'
            . ($configured ? 'Secret’ı değiştir' : 'Secret’ı kaydet') . '</button></form>'
            . $delete . '</article>';
    }

    private static function control(IntegrationSettingDefinition $definition, mixed $value): string
    {
        $id = self::escape('setting-' . $definition->key);
        $current = self::rawValue($value);

        if ($definition->type === IntegrationSettingType::Flag) {
            return '<select class="int-control" id="' . $id . '" name="value">'
                . '<option value="1"' . ($value === true ? ' selected' : '') . '>Enabled</option>'
                . '<option value="0"' . ($value === false ? ' selected' : '') . '>Disabled</option></select>';
        }

        if ($definition->type === IntegrationSettingType::Enum) {
            $options = '';
            foreach ($definition->allowedValues as $allowed) {
                $options .= '<option value="' . self::escape($allowed) . '"'
                    . ((string) $value === $allowed ? ' selected' : '') . '>'
                    . self::escape($allowed) . '</option>';
            }

            return '<select class="int-control" id="' . $id . '" name="value">' . $options . '</select>';
        }

        if (in_array($definition->type, [IntegrationSettingType::StringList, IntegrationSettingType::HttpsUrlList], true)) {
            return '<textarea class="int-control" id="' . $id . '" name="value" rows="4" maxlength="'
                . min(20000, max(500, $definition->maxLength * 20)) . '">'
                . self::escape($current) . '</textarea>';
        }

        $type = match ($definition->type) {
            IntegrationSettingType::Integer => 'number',
            IntegrationSettingType::Email => 'email',
            IntegrationSettingType::HttpsUrl => 'url',
            default => 'text',
        };
        $constraints = '';
        if ($definition->type === IntegrationSettingType::Integer) {
            if ($definition->minimum !== null) {
                $constraints .= ' min="' . $definition->minimum . '"';
            }
            if ($definition->maximum !== null) {
                $constraints .= ' max="' . $definition->maximum . '"';
            }
        } else {
            $constraints .= ' maxlength="' . $definition->maxLength . '"';
        }

        return '<input class="int-control" id="' . $id . '" type="' . $type
            . '" name="value" value="' . self::escape($current) . '"' . $constraints . '>';
    }

    private static function sectionOptions(?IntegrationSection $selected): string
    {
        $html = '';
        foreach (IntegrationSection::cases() as $section) {
            $html .= '<option value="' . self::escape($section->value) . '"'
                . ($selected === $section ? ' selected' : '') . '>'
                . self::escape($section->label()) . '</option>';
        }

        return $html;
    }

    private static function hidden(
        string $csrf,
        string $action,
        string $key,
        ?IntegrationSection $section,
    ): string {
        return '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="action" value="' . self::escape($action) . '">'
            . '<input type="hidden" name="key" value="' . self::escape($key) . '">'
            . '<input type="hidden" name="return_section" value="'
            . self::escape($section?->value ?? 'all') . '">';
    }

    private static function matchesSetting(IntegrationSettingDefinition $definition, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        return str_contains(strtolower(implode(' ', [
            $definition->key,
            $definition->configPath,
            $definition->label,
            $definition->description,
            $definition->section->label(),
            $definition->availabilityNote ?? '',
        ])), strtolower($search));
    }

    private static function matchesSecret(IntegrationSecretDefinition $definition, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        return str_contains(strtolower(implode(' ', [
            $definition->key,
            $definition->label,
            $definition->description,
            $definition->section->label(),
        ])), strtolower($search));
    }

    private static function capability(CapabilityEntry $capability): string
    {
        return '<div class="int-capability" data-ok="' . ($capability->available ? '1' : '0') . '">'
            . '<span class="int-dot" aria-hidden="true"></span><div><strong>'
            . self::escape($capability->name) . '</strong><div class="int-meta">'
            . ($capability->available ? 'available' : 'unavailable')
            . ($capability->requiredForMinimumProfile ? ' · minimum profile' : ' · optional')
            . ($capability->detail !== null ? ' · ' . self::escape($capability->detail) : '')
            . '</div></div></div>';
    }

    private static function displayValue(mixed $value): string
    {
        if ($value === null) {
            return '(null / default yok)';
        }
        if (is_bool($value)) {
            return $value ? 'enabled' : 'disabled';
        }
        if (is_array($value)) {
            return $value === [] ? '(boş liste)' : implode("\n", array_map(
                static fn (mixed $entry): string => is_scalar($entry) ? (string) $entry : '[complex]',
                $value,
            ));
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '[complex]';
    }

    private static function rawValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode("\n", array_map(
                static fn (mixed $entry): string => is_scalar($entry) ? (string) $entry : '',
                $value,
            ));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
