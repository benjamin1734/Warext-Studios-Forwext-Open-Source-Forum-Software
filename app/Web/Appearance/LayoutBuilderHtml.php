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
            . '<div><h1 class="builder-title">Layout Builder</h1><p class="muted">Widget taşı, sırala, çoğalt; koşul ve cihaz önizlemesi yap; taslak kaydet ve güvenli yayınla.</p></div>'
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
