<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Module\FirstParty\FirstPartyModuleDataState;
use Forwext\Core\Module\FirstParty\FirstPartyModuleDefinition;
use Forwext\Core\Module\FirstParty\FirstPartyModuleRecord;
use Forwext\Core\Module\FirstParty\FirstPartyModuleScope;
use Forwext\Core\Module\FirstParty\FirstPartyModuleSettingDefinition;
use Forwext\Core\Module\FirstParty\FirstPartyModuleSettingType;
use Forwext\Core\Module\FirstParty\FirstPartyModuleState;
use Forwext\Core\Routing\BasePath;

final class AdminModuleManagerHtml
{
    /** @param array<string,mixed> $snapshot */
    public static function page(
        array $snapshot,
        BasePath $basePath,
        string $csrf,
        bool $updated,
        string $search,
        string $state,
    ): string {
        /** @var list<FirstPartyModuleDefinition> $definitions */
        $definitions = $snapshot['definitions'];
        /** @var array<string,FirstPartyModuleRecord> $records */
        $records = $snapshot['records'];
        /** @var ?FirstPartyModuleDefinition $selected */
        $selected = $snapshot['selected'];
        /** @var ?FirstPartyModuleRecord $selectedRecord */
        $selectedRecord = $snapshot['selected_record'];
        /** @var array<string,bool|int|string> $settings */
        $settings = $snapshot['settings'];
        /** @var list<array{id:string,label:string}> $scopeTargets */
        $scopeTargets = $snapshot['scope_targets'];
        $selectedScope = $snapshot['selected_scope'];
        $selectedScopeId = $snapshot['selected_scope_id'];
        $pendingStorage = (int) $snapshot['pending_storage_count'];
        $search = trim($search);
        $visibleDefinitions = array_values(array_filter(
            $definitions,
            static function (FirstPartyModuleDefinition $definition) use ($records, $search, $state): bool {
                $record = $records[$definition->key];
                if ($state !== 'all' && $record->state->value !== $state) {
                    return false;
                }
                if ($search === '') {
                    return true;
                }
                $haystack = strtolower($definition->key . ' ' . $definition->label . ' ' . $definition->description);

                return str_contains($haystack, strtolower($search));
            },
        ));

        $action = self::escape($basePath->prepend('/admin/modules'));
        $breadcrumbs = AdminBreadcrumbsHtml::render([
            ['label'=>'Admin', 'path'=>'/admin'],
            ['label'=>'First-party Modüller', 'path'=>null],
        ], $basePath);

        $moduleList = '';
        foreach ($visibleDefinitions as $definition) {
            $record = $records[$definition->key];
            $selectedClass = $selected?->key === $definition->key ? ' is-selected' : '';
            $moduleList .= '<a class="mod-list-item' . $selectedClass . '" href="'
                . self::moduleUrl($action, $definition->key, $search, $state) . '"><span><strong>'
                . self::escape($definition->label) . '</strong><small>' . self::escape($definition->key)
                . '</small></span><span class="mod-state state-' . self::escape($record->state->value) . '">'
                . self::escape(self::stateLabel($record->state)) . '</span></a>';
        }

        if ($moduleList === '') {
            $moduleList = '<p class="mod-muted">Filtreyle eşleşen first-party modül yok.</p>';
        }

        $detail = $selected !== null && $selectedRecord !== null
            ? self::detail(
                $selected,
                $selectedRecord,
                $records,
                $settings,
                $scopeTargets,
                $selectedScope,
                $selectedScopeId,
                $pendingStorage,
                $snapshot['dependents'],
                $snapshot['conflicts'],
                $action,
                $csrf,
            )
            : '<section class="mod-empty"><h2>Bir modül seç</h2><p>Lifecycle, dependency graph, veri politikası ve scope ayarlarını yönetmek için soldan bir first-party modül seç.</p></section>';

        $notice = $updated
            ? '<div class="mod-notice" role="status">Modül yapılandırması güncellendi.</div>'
            : '';
        $stateOptions = '';
        foreach (['all'=>'Tüm durumlar','enabled'=>'Aktif','disabled'=>'Kapalı','uninstalled'=>'Kaldırılmış'] as $key=>$label) {
            $stateOptions .= '<option value="' . self::escape($key) . '"' . ($state === $key ? ' selected' : '') . '>'
                . self::escape($label) . '</option>';
        }
        $filter = '<form class="mod-filter" method="get" action="' . $action . '">'
            . '<label>Modüllerde ara<input name="q" maxlength="80" value="' . self::escape($search)
            . '" placeholder="Ad, key veya açıklama"></label><label>Durum<select name="state">' . $stateOptions
            . '</select></label><button class="mod-button primary" type="submit">Filtrele</button>'
            . '<a class="mod-button" href="' . $action . '">Filtreyi sıfırla</a></form>';

        return '<section class="module-manager"><style>'
            . '.module-manager{display:grid;gap:18px}.mod-shell{display:grid;grid-template-columns:minmax(240px,320px) minmax(0,1fr);gap:16px}.mod-panel,.mod-sidebar,.mod-empty{border:1px solid var(--line);background:var(--panel);border-radius:14px;padding:16px}'
            . '.mod-sidebar{display:grid;gap:8px;align-content:start}.mod-list-item{display:flex;justify-content:space-between;gap:10px;align-items:center;border:1px solid var(--line);border-radius:10px;background:var(--panel2);color:var(--text);padding:11px;text-decoration:none}.mod-list-item span:first-child{display:grid;gap:2px}.mod-list-item small,.mod-muted{color:var(--muted)}.mod-list-item.is-selected{outline:2px solid var(--accent);outline-offset:1px}'
            . '.mod-state{display:inline-flex;align-items:center;border:1px solid var(--line);border-radius:999px;padding:4px 8px;font-size:.82rem;white-space:nowrap}.state-enabled{font-weight:700}.state-uninstalled{opacity:.72}.mod-detail{display:grid;gap:14px}.mod-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}.mod-head h1,.mod-panel h2,.mod-card h3{margin:0}.mod-head p,.mod-card p{margin:5px 0 0}.mod-notice{border:1px solid var(--line);background:var(--panel);border-radius:10px;padding:11px}'
            . '.mod-filter{display:grid;grid-template-columns:minmax(0,1fr) minmax(150px,220px) auto auto;gap:8px;align-items:end}.mod-filter label{display:grid;gap:5px}.mod-filter input,.mod-filter select{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);padding:9px 10px}'
            . '.mod-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}.mod-card{border:1px solid var(--line);background:var(--panel2);border-radius:11px;padding:13px}.mod-actions{display:flex;gap:8px;flex-wrap:wrap}.mod-actions form{margin:0}.mod-button{border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);padding:9px 12px;cursor:pointer}.mod-button.primary{font-weight:700}.mod-button.danger{font-weight:700}.mod-warning{border:1px solid var(--line);border-radius:10px;padding:12px;background:var(--panel2)}'
            . '.mod-scope-nav{display:flex;flex-wrap:wrap;gap:7px}.mod-scope-nav a{border:1px solid var(--line);border-radius:999px;padding:7px 10px;text-decoration:none;color:var(--text)}.mod-scope-nav a[aria-current="page"]{outline:2px solid var(--accent);outline-offset:1px}.mod-target{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.mod-field{display:grid;gap:5px;min-width:180px;flex:1}.mod-field input,.mod-field select{border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);padding:9px 10px}.mod-setting{display:grid;grid-template-columns:minmax(0,1fr) minmax(180px,280px);gap:14px;align-items:end;border-top:1px solid var(--line);padding:13px 0}.mod-setting:first-of-type{border-top:0}.mod-setting-actions{display:flex;gap:7px;align-items:end;flex-wrap:wrap}.mod-setting-actions form{margin:0;flex:1}.mod-setting-actions form .mod-field{min-width:0}.mod-graph{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.mod-graph ul{margin:8px 0 0;padding-left:20px}.mod-confirm{width:190px;border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);padding:9px 10px}'
            . AdminUxQualityHtml::css()
            . '.acp-breadcrumbs ol{display:flex;flex-wrap:wrap;gap:7px;list-style:none;padding:0;margin:0;color:var(--muted)}.acp-breadcrumbs li+li:before{content:"/";margin-right:7px}.acp-breadcrumbs a{color:inherit}'
            . '@media(max-width:900px){.mod-shell{grid-template-columns:1fr}.mod-sidebar{grid-template-columns:repeat(auto-fit,minmax(210px,1fr))}.mod-graph{grid-template-columns:1fr}}'
            . '@media(max-width:640px){.mod-detail .mod-head,.mod-setting{grid-template-columns:1fr;display:grid}.mod-actions,.mod-setting-actions,.mod-target,.mod-filter{display:grid;grid-template-columns:1fr}.mod-button,.mod-confirm{width:100%}}'
            . '</style>'
            . $breadcrumbs
            . AdminUxQualityHtml::guidance(
                'First-party modülleri ad/key/açıklama ve lifecycle durumuna göre filtrele; dependency graph ile etki alanını kontrol et.',
                'Scoped ayarlar post → thread → forum → group → global → güvenli varsayılan sırasıyla çözülür; filtreleme hiçbir state değiştirmez.',
                'Effective değer, dependency/conflict graph ve veri durumu mutasyondan önce görünür.',
                'Setting override kaldırılabilir; uninstall sırasında veriyi koruma seçeneği vardır ve veri silme exact module key onayı ister.',
            )
            . $notice
            . $filter
            . '<div class="mod-shell"><aside class="mod-sidebar" aria-label="First-party modüller">' . $moduleList
            . '</aside><main class="mod-detail">' . $detail . '</main></div></section>';
    }

    /**
     * @param array<string,FirstPartyModuleRecord> $records
     * @param array<string,bool|int|string> $settings
     * @param list<array{id:string,label:string}> $scopeTargets
     * @param list<FirstPartyModuleDefinition> $dependents
     * @param list<FirstPartyModuleDefinition> $conflicts
     */
    private static function detail(
        FirstPartyModuleDefinition $definition,
        FirstPartyModuleRecord $record,
        array $records,
        array $settings,
        array $scopeTargets,
        FirstPartyModuleScope $selectedScope,
        ?string $selectedScopeId,
        int $pendingStorage,
        array $dependents,
        array $conflicts,
        string $action,
        string $csrf,
    ): string {
        $head = '<section class="mod-panel"><div class="mod-head"><div><span class="mod-state state-'
            . self::escape($record->state->value) . '">' . self::escape(self::stateLabel($record->state))
            . '</span><h1>' . self::escape($definition->label) . '</h1><p class="mod-muted">'
            . self::escape($definition->description) . '</p></div><div><small class="mod-muted">Data: '
            . self::escape(self::dataLabel($record->dataState)) . '</small></div></div>'
            . '<div class="mod-grid"><article class="mod-card"><h3>Route kapsamı</h3><p>'
            . self::escape($definition->routePrefixes === [] ? 'Route dışı / dahili entegrasyon' : implode(', ', $definition->routePrefixes))
            . '</p></article><article class="mod-card"><h3>Silme kapsamı</h3><p>'
            . count($definition->purgeTables) . ' tablo · ' . count($definition->storagePathQueries)
            . ' storage kaynağı</p></article><article class="mod-card"><h3>Son değişiklik</h3><p>'
            . self::escape($record->updatedAt?->format('Y-m-d H:i:s') ?? 'Migration varsayılanı')
            . '</p></article></div>'
            . self::lifecycle($definition, $record, $pendingStorage, $action, $csrf)
            . '</section>';

        $graph = '<section class="mod-panel"><h2>Dependency / Conflict Graph</h2><p class="mod-muted">Lifecycle işlemleri backend’de bu graph üzerinden doğrulanır; yalnız buton gizlemeye güvenilmez.</p><div class="mod-graph">'
            . self::graphColumn('Bağımlılıklar', $definition->dependencies, $records)
            . self::graphDefinitionColumn('Bağımlı modüller', $dependents, $records)
            . self::graphDefinitionColumn('Çakışmalar', $conflicts, $records)
            . '</div></section>';

        $settingsHtml = self::settings(
            $definition,
            $record,
            $settings,
            $scopeTargets,
            $selectedScope,
            $selectedScopeId,
            $action,
            $csrf,
        );

        return $head . $graph . $settingsHtml;
    }

    private static function lifecycle(
        FirstPartyModuleDefinition $definition,
        FirstPartyModuleRecord $record,
        int $pendingStorage,
        string $action,
        string $csrf,
    ): string {
        $html = '<div class="mod-actions" style="margin-top:14px">';
        if ($record->state === FirstPartyModuleState::Enabled) {
            $html .= self::actionForm($action, $csrf, $definition->key, 'disable', 'Devre dışı bırak');
        } elseif ($record->state === FirstPartyModuleState::Disabled) {
            $html .= self::actionForm($action, $csrf, $definition->key, 'enable', 'Etkinleştir', true);
        } elseif ($record->state === FirstPartyModuleState::Uninstalled
            && $record->dataState !== FirstPartyModuleDataState::PurgePending
        ) {
            $html .= self::actionForm($action, $csrf, $definition->key, 'install', 'Kur', true);
        }
        if ($record->state === FirstPartyModuleState::Uninstalled
            && $record->dataState === FirstPartyModuleDataState::PurgePending
        ) {
            $html .= self::actionForm(
                $action,
                $csrf,
                $definition->key,
                'retry_purge',
                'Storage temizliğini yeniden dene (' . $pendingStorage . ')',
                true,
            );
        }
        $html .= '</div>';

        if ($record->state === FirstPartyModuleState::Disabled) {
            $html .= '<div class="mod-warning" style="margin-top:12px"><strong>Uninstall veri politikası</strong>'
                . '<p class="mod-muted">Keep data modülü kaldırır ancak veriyi korur. Delete data kayıtlı modül tablolarını ve scope ayarlarını siler; storage nesneleri güvenli purge kuyruğundan temizlenir. İşlemden önce modül anahtarını birebir yazman gerekir.</p>'
                . '<div class="mod-actions">'
                . self::uninstallForm($action, $csrf, $definition->key, false)
                . self::uninstallForm($action, $csrf, $definition->key, true)
                . '</div></div>';
        }

        return $html;
    }

    private static function settings(
        FirstPartyModuleDefinition $definition,
        FirstPartyModuleRecord $record,
        array $settings,
        array $scopeTargets,
        FirstPartyModuleScope $selectedScope,
        ?string $selectedScopeId,
        string $action,
        string $csrf,
    ): string {
        $supported = [];
        foreach ($definition->settings as $setting) {
            foreach ($setting->scopes as $scope) {
                $supported[$scope->value] = $scope;
            }
        }

        $scopeNav = '<nav class="mod-scope-nav" aria-label="Modül ayar scope">';
        foreach (FirstPartyModuleScope::cases() as $scope) {
            if (!isset($supported[$scope->value])) {
                continue;
            }
            $url = $action . '?module=' . rawurlencode($definition->key)
                . '&scope=' . rawurlencode($scope->value);
            $scopeNav .= '<a href="' . self::escape($url) . '"'
                . ($selectedScope === $scope ? ' aria-current="page"' : '') . '>'
                . self::escape(self::scopeLabel($scope)) . '</a>';
        }
        $scopeNav .= '</nav>';

        $target = '';
        if ($selectedScope->needsTarget()) {
            $options = '';
            foreach ($scopeTargets as $option) {
                $options .= '<option value="' . self::escape($option['id']) . '">'
                    . self::escape($option['label']) . '</option>';
            }
            $target = '<form class="mod-target" method="get" action="' . $action . '">'
                . '<input type="hidden" name="module" value="' . self::escape($definition->key) . '">'
                . '<input type="hidden" name="scope" value="' . self::escape($selectedScope->value) . '">'
                . '<label class="mod-field"><span>Scope target ID</span><input name="scope_id" maxlength="191" required list="module-scope-targets" value="'
                . self::escape($selectedScopeId ?? '') . '" placeholder="Forum / group / thread / post ID"></label>'
                . '<datalist id="module-scope-targets">' . $options . '</datalist>'
                . '<button class="mod-button primary" type="submit">Targetı yükle</button></form>';
        }

        $body = '';
        $targetReady = !$selectedScope->needsTarget() || $selectedScopeId !== null;
        if ($record->state === FirstPartyModuleState::Uninstalled) {
            $body = '<p class="mod-muted">Uninstalled modülün ayarları düzenlenemez. Önce modülü kur.</p>';
        } elseif (!$targetReady) {
            $body = '<p class="mod-muted">Bu scope için ayarları görüntülemek ve değiştirmek üzere geçerli bir target ID seç.</p>';
        } else {
            foreach ($definition->settings as $setting) {
                if (!$setting->supports($selectedScope)) {
                    continue;
                }
                $body .= self::settingRow(
                    $definition,
                    $setting,
                    $settings,
                    $selectedScope,
                    $selectedScopeId,
                    $action,
                    $csrf,
                );
            }
            if ($body === '') {
                $body = '<p class="mod-muted">Bu modül seçilen scope için ayar tanımlamıyor.</p>';
            }
        }

        return '<section class="mod-panel"><h2>Scoped settings</h2><p class="mod-muted">Öncelik post → thread → forum → group → global → güvenli varsayılan şeklindedir. Reset, bu scope override’ını kaldırır ve üst fallback’i yeniden etkinleştirir.</p>'
            . $scopeNav . $target . '<div style="margin-top:12px">' . $body . '</div></section>';
    }

    /**
     * @param array<string,bool|int|string> $settings
     */
    private static function settingRow(
        FirstPartyModuleDefinition $module,
        FirstPartyModuleSettingDefinition $setting,
        array $settings,
        FirstPartyModuleScope $scope,
        ?string $scopeId,
        string $action,
        string $csrf,
    ): string {
        $hasOverride = array_key_exists($setting->key, $settings);
        $value = $hasOverride ? $settings[$setting->key] : $setting->defaultValue;
        $input = self::settingInput($setting, $value);

        $hidden = '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="module_key" value="' . self::escape($module->key) . '">'
            . '<input type="hidden" name="setting_key" value="' . self::escape($setting->key) . '">'
            . '<input type="hidden" name="scope" value="' . self::escape($scope->value) . '">'
            . ($scope->needsTarget()
                ? '<input type="hidden" name="scope_id" value="' . self::escape($scopeId ?? '') . '">'
                : '');

        $reset = $hasOverride
            ? '<form method="post" action="' . $action . '">' . $hidden
                . '<input type="hidden" name="action" value="reset_setting">'
                . '<button class="mod-button" type="submit">Override’ı kaldır</button></form>'
            : '';

        return '<article class="mod-setting"><div><h3>' . self::escape($setting->label) . '</h3><p>'
            . self::escape($setting->description) . '</p><small class="mod-muted">'
            . ($hasOverride ? 'Bu scope için override aktif.' : 'Override yok; fallback/default gösteriliyor.')
            . ' · Varsayılan: ' . self::escape(self::displayValue($setting->defaultValue)) . '</small></div>'
            . '<div class="mod-setting-actions"><form method="post" action="' . $action . '">' . $hidden
            . '<input type="hidden" name="action" value="save_setting"><label class="mod-field"><span>Değer</span>'
            . $input . '</label><button class="mod-button primary" type="submit">Kaydet</button></form>'
            . $reset . '</div></article>';
    }

    private static function settingInput(
        FirstPartyModuleSettingDefinition $setting,
        bool|int|string $value,
    ): string {
        if ($setting->type === FirstPartyModuleSettingType::Flag) {
            return '<select name="value"><option value="1"' . ($value === true ? ' selected' : '')
                . '>Açık</option><option value="0"' . ($value === false ? ' selected' : '') . '>Kapalı</option></select>';
        }

        if ($setting->type === FirstPartyModuleSettingType::Integer) {
            return '<input type="number" name="value" required value="' . self::escape((string) $value) . '"'
                . ($setting->minimum !== null ? ' min="' . $setting->minimum . '"' : '')
                . ($setting->maximum !== null ? ' max="' . $setting->maximum . '"' : '') . '>';
        }

        if ($setting->allowedStrings !== []) {
            $options = '';
            foreach ($setting->allowedStrings as $allowed) {
                $options .= '<option value="' . self::escape($allowed) . '"'
                    . ($value === $allowed ? ' selected' : '') . '>' . self::escape($allowed) . '</option>';
            }

            return '<select name="value">' . $options . '</select>';
        }

        return '<input type="text" name="value" maxlength="500" required value="'
            . self::escape((string) $value) . '">';
    }

    /** @param list<string> $keys @param array<string,FirstPartyModuleRecord> $records */
    private static function graphColumn(string $label, array $keys, array $records): string
    {
        $items = [];
        foreach ($keys as $key) {
            $record = $records[$key] ?? null;
            $items[] = '<li><code>' . self::escape($key) . '</code>'
                . ($record !== null ? ' · ' . self::escape(self::stateLabel($record->state)) : '') . '</li>';
        }

        return '<article class="mod-card"><h3>' . self::escape($label) . '</h3>'
            . ($items === [] ? '<p class="mod-muted">Yok</p>' : '<ul>' . implode('', $items) . '</ul>')
            . '</article>';
    }

    /**
     * @param list<FirstPartyModuleDefinition> $definitions
     * @param array<string,FirstPartyModuleRecord> $records
     */
    private static function graphDefinitionColumn(string $label, array $definitions, array $records): string
    {
        return self::graphColumn(
            $label,
            array_map(static fn (FirstPartyModuleDefinition $definition): string => $definition->key, $definitions),
            $records,
        );
    }

    private static function actionForm(
        string $action,
        string $csrf,
        string $moduleKey,
        string $operation,
        string $label,
        bool $primary = false,
    ): string {
        return '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="action" value="' . self::escape($operation) . '">'
            . '<input type="hidden" name="module_key" value="' . self::escape($moduleKey) . '">'
            . '<button class="mod-button' . ($primary ? ' primary' : '') . '" type="submit">'
            . self::escape($label) . '</button></form>';
    }

    private static function uninstallForm(
        string $action,
        string $csrf,
        string $moduleKey,
        bool $deleteData,
    ): string {
        return '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="action" value="' . ($deleteData ? 'uninstall_delete' : 'uninstall_keep') . '">'
            . '<input type="hidden" name="module_key" value="' . self::escape($moduleKey) . '">'
            . '<label class="mod-field"><span><code>' . self::escape($moduleKey) . '</code> yaz</span>'
            . '<input class="mod-confirm" name="confirm_key" maxlength="64" required autocomplete="off"></label>'
            . '<button class="mod-button' . ($deleteData ? ' danger' : '') . '" type="submit">'
            . ($deleteData ? 'Uninstall + veriyi sil' : 'Uninstall + veriyi koru') . '</button></form>';
    }

    private static function stateLabel(FirstPartyModuleState $state): string
    {
        return match ($state) {
            FirstPartyModuleState::Enabled => 'Enabled',
            FirstPartyModuleState::Disabled => 'Disabled',
            FirstPartyModuleState::Uninstalled => 'Uninstalled',
        };
    }

    private static function dataLabel(FirstPartyModuleDataState $state): string
    {
        return match ($state) {
            FirstPartyModuleDataState::Retained => 'Retained',
            FirstPartyModuleDataState::PurgePending => 'Purge pending',
            FirstPartyModuleDataState::Purged => 'Purged',
        };
    }

    private static function scopeLabel(FirstPartyModuleScope $scope): string
    {
        return match ($scope) {
            FirstPartyModuleScope::Global => 'Global',
            FirstPartyModuleScope::Forum => 'Forum',
            FirstPartyModuleScope::Group => 'Group',
            FirstPartyModuleScope::Thread => 'Thread',
            FirstPartyModuleScope::Post => 'Post',
        };
    }

    private static function moduleUrl(string $action, string $moduleKey, string $search, string $state): string
    {
        $query = ['module'=>$moduleKey];
        if ($search !== '') {
            $query['q'] = $search;
        }
        if ($state !== 'all') {
            $query['state'] = $state;
        }

        return self::escape($action . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    private static function displayValue(bool|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Açık' : 'Kapalı';
        }

        return (string) $value;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
