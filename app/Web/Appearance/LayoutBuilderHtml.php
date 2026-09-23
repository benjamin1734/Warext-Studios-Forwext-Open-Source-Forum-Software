<?php

declare(strict_types=1);

namespace Forwext\App\Web\Appearance;

use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Layout\Builder\LayoutBuilderSnapshot;
use Forwext\Core\Ui\Layout\Builder\LayoutDocument;
use Forwext\Core\Ui\Layout\UiSlotDefinition;
use Forwext\Core\Ui\Widget\RegisteredWidget;
use JsonException;

final class LayoutBuilderHtml
{
    /**
     * @param list<UiSlotDefinition> $slots
     * @param list<RegisteredWidget> $widgets
     */
    public static function page(
        LayoutBuilderSnapshot $snapshot,
        array $slots,
        array $widgets,
        BasePath $basePath,
        string $csrf,
        bool $saved,
        bool $published,
        bool $imported,
    ): string {
        $document = $snapshot->draft?->document
            ?? $snapshot->published?->document
            ?? new LayoutDocument([]);

        try {
            $documentJson = json_encode(
                $document->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            $documentJson = '{"version":1,"placements":[]}';
        }

        $action = self::escape($basePath->prepend('/admin/appearance/layout'));
        $export = self::escape($basePath->prepend('/admin/appearance/layout/export.json'));
        $script = self::escape($basePath->prepend('/assets/layout-builder.js'));
        $draftId = $snapshot->draft?->revisionId->value();

        $notice = '';
        if ($saved) {
            $notice = '<div class="builder-notice">Taslak kaydedildi.</div>';
        } elseif ($published) {
            $notice = '<div class="builder-notice">Düzen güvenli şekilde yayınlandı.</div>';
        } elseif ($imported) {
            $notice = '<div class="builder-notice">İçe aktarılan düzen taslak olarak kaydedildi.</div>';
        }

        $slotHtml = '';
        foreach ($slots as $slot) {
            $slotHtml .= '<section class="builder-slot" data-layout-slot="' . self::escape($slot->key) . '">'
                . '<header><strong>' . self::escape($slot->key) . '</strong>'
                . '<span>' . self::escape($slot->region->value) . '</span></header>'
                . '<div class="builder-dropzone" data-layout-dropzone="' . self::escape($slot->key) . '"></div>'
                . '</section>';
        }

        $widgetHtml = '';
        foreach ($widgets as $registered) {
            $widget = $registered->widget;
            $widgetHtml .= '<button type="button" class="builder-widget-add"'
                . ' data-widget-key="' . self::escape($widget->key()) . '"'
                . ' data-widget-slot="' . self::escape($widget->slot()) . '">'
                . '<strong>' . self::escape($widget->key()) . '</strong>'
                . '<small>' . self::escape($registered->ownerType->value . ':' . $registered->ownerKey) . '</small>'
                . '</button>';
        }

        $publishForm = $draftId === null
            ? '<p class="muted">Yayınlamak için önce taslağı kaydet.</p>'
            : '<form method="post" action="' . $action . '" class="builder-publish-form">'
                . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
                . '<input type="hidden" name="action" value="publish">'
                . '<input type="hidden" name="draft_revision_id" value="' . self::escape($draftId) . '">'
                . '<button type="submit">Taslağı yayınla</button></form>';

        return '<section class="card builder-admin" data-layout-builder>'
            . '<style>'
            . '.builder-admin{display:grid;gap:18px}.builder-toolbar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}'
            . '.builder-toolbar button,.builder-toolbar a,.builder-publish-form button,.builder-import button{border:1px solid var(--line);background:var(--panel2);color:var(--text);padding:9px 12px;border-radius:9px;text-decoration:none;cursor:pointer}'
            . '.builder-notice{padding:10px 12px;border:1px solid #2f6f47;background:#173722;border-radius:9px}'
            . '.builder-grid{display:grid;grid-template-columns:minmax(180px,240px) minmax(0,1fr);gap:18px}'
            . '.builder-palette{display:grid;align-content:start;gap:8px}.builder-widget-add{text-align:left;display:grid;gap:3px}.builder-widget-add small{color:var(--muted)}'
            . '.builder-preview-shell{overflow:auto;border:1px solid var(--line);border-radius:12px;padding:12px;background:var(--bg)}'
            . '.builder-preview{margin-inline:auto;transition:max-width .15s ease}.builder-preview[data-device="desktop"]{max-width:1180px}.builder-preview[data-device="tablet"]{max-width:820px}.builder-preview[data-device="mobile"]{max-width:390px}'
            . '.builder-slot{border:1px dashed var(--line);border-radius:10px;margin:10px 0;background:var(--panel)}.builder-slot>header{display:flex;justify-content:space-between;gap:10px;padding:9px 10px;color:var(--muted)}'
            . '.builder-dropzone{min-height:52px;padding:8px;display:grid;gap:8px}.builder-placement{border:1px solid var(--line);border-radius:9px;background:var(--panel2);padding:10px;display:grid;gap:8px;cursor:grab}'
            . '.builder-placement[hidden]{display:none}.builder-placement-head{display:flex;justify-content:space-between;gap:8px;align-items:center}.builder-placement-actions{display:flex;gap:6px}.builder-placement-actions button{padding:5px 8px}'
            . '.builder-condition{display:grid;grid-template-columns:2fr 1fr;gap:8px}.builder-condition label{display:grid;gap:4px;font-size:12px;color:var(--muted)}.builder-condition input,.builder-condition select,.builder-import textarea{width:100%;background:#0d1117;color:var(--text);border:1px solid var(--line);border-radius:7px;padding:7px}'
            . '.builder-device-checks{display:flex;gap:8px;flex-wrap:wrap}.builder-preview-controls{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.builder-preview-controls label{display:grid;gap:4px}'
            . '.builder-import{display:grid;gap:8px}.builder-import textarea{min-height:130px}.builder-status{font-size:13px;color:var(--muted)}'
            . '@media(max-width:760px){.builder-grid{grid-template-columns:1fr}.builder-condition{grid-template-columns:1fr}}'
            . '</style>'
            . '<div><h1 style="margin:0">Layout Builder</h1><p class="muted">Widget taşı, sırala, çoğalt; koşul ve cihaz önizlemesi yap; taslak kaydet ve güvenli yayınla.</p></div>'
            . $notice
            . '<div class="builder-toolbar">'
            . '<button type="button" data-builder-undo disabled>Geri al</button>'
            . '<button type="button" data-builder-redo disabled>Yinele</button>'
            . '<button type="button" data-builder-device="desktop">Desktop</button>'
            . '<button type="button" data-builder-device="tablet">Tablet</button>'
            . '<button type="button" data-builder-device="mobile">Mobile</button>'
            . '<a href="' . $export . '">JSON dışa aktar</a>'
            . '</div>'
            . '<div class="builder-preview-controls">'
            . '<label>Önizleme route<input type="text" value="home" maxlength="128" data-preview-route></label>'
            . '<label>Kitle<select data-preview-audience><option value="guest">Misafir</option><option value="member" selected>Üye</option></select></label>'
            . '<span class="builder-status" data-builder-status></span>'
            . '</div>'
            . '<form method="post" action="' . $action . '" data-builder-form>'
            . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="action" value="save">'
            . '<textarea name="document_json" data-builder-document hidden>' . self::escape($documentJson) . '</textarea>'
            . '<div class="builder-grid"><aside class="builder-palette"><h2>Widgetlar</h2>' . $widgetHtml . '</aside>'
            . '<div class="builder-preview-shell"><div class="builder-preview" data-builder-preview data-device="desktop">' . $slotHtml . '</div></div></div>'
            . '<div class="builder-toolbar"><button type="submit">Taslağı kaydet</button></div></form>'
            . '<div><h2>Yayın</h2>' . $publishForm . '</div>'
            . '<form method="post" action="' . $action . '" class="builder-import">'
            . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="action" value="import">'
            . '<label>Forwext layout JSON<textarea name="import_json" maxlength="1048576" required></textarea></label>'
            . '<button type="submit">JSON içe aktar</button></form>'
            . '<script src="' . $script . '" defer></script>'
            . '</section>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
