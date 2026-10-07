<?php

declare(strict_types=1);

namespace Forwext\App\Web\Appearance;

use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Theme\ThemeDefinition;
use Forwext\Core\Ui\Theme\ThemeDiffEntry;
use Forwext\Core\Ui\Theme\ThemeSnapshot;
use JsonException;

final class ThemeManageHtml
{
    /**
     * @param list<ThemeDefinition> $themes
     * @param list<ThemeDiffEntry> $diff
     */
    public static function page(
        array $themes,
        ?ThemeSnapshot $snapshot,
        array $diff,
        BasePath $basePath,
        string $csrf,
        bool $advanced,
        bool $saved,
        bool $published,
        bool $rolledBack,
    ): string {
        $action = self::escape($basePath->prepend('/admin/appearance/themes'));
        $selected = $snapshot?->theme;
        $staging = $snapshot?->staging;

        $templates = $staging?->payload->templates ?? [];
        $phrases = $staging?->payload->phrases ?? [];
        $phraseCount = 0;
        foreach ($phrases as $entries) {
            $phraseCount += count($entries);
        }

        try {
            $templatesJson = json_encode(
                $templates,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $phrasesJson = json_encode(
                $phrases,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            $templatesJson = '{}';
            $phrasesJson = '{}';
        }

        $notice = '';
        if ($saved) {
            $notice = '<div class="theme-notice">Staging revision kaydedildi.</div>';
        } elseif ($published) {
            $notice = '<div class="theme-notice">Tema yayınlandı ve template cache derlendi.</div>';
        } elseif ($rolledBack) {
            $notice = '<div class="theme-notice">Seçilen revision staging hedefi yapıldı.</div>';
        }

        $themeById = [];
        foreach ($themes as $theme) {
            $themeById[$theme->themeId->value()] = $theme;
        }

        $themeList = '<a class="theme-list-item theme-list-create" href="' . $action . '"><strong>+ Yeni tema</strong><small>Yeni revision zinciri başlat</small></a>';
        foreach ($themes as $theme) {
            $states = [];
            if ($theme->publishedRevisionId !== null) {
                $states[] = 'published';
            }
            if ($theme->stagingRevisionId !== null) {
                $states[] = 'staging';
            }
            $themeList .= '<a class="theme-list-item" href="' . $action . '?theme='
                . rawurlencode($theme->key) . '"'
                . ($selected !== null && $theme->themeId->equals($selected->themeId) ? ' aria-current="page"' : '')
                . '><span class="theme-list-head"><strong>' . self::escape($theme->name) . '</strong>'
                . '<span class="theme-list-state">' . self::escape($states === [] ? 'draftless' : implode(' · ', $states)) . '</span></span>'
                . '<small>' . self::escape($theme->key) . ' · ' . self::escape($theme->updatedAt->format('Y-m-d H:i')) . ' UTC</small></a>';
        }

        $parentOptions = '<option value="">Base theme</option>';
        foreach ($themes as $theme) {
            if ($selected !== null && $theme->themeId->equals($selected->themeId)) {
                continue;
            }
            $isSelected = $selected?->parentThemeId !== null
                && $selected->parentThemeId->equals($theme->themeId);
            $parentOptions .= '<option value="' . self::escape($theme->key) . '"'
                . ($isSelected ? ' selected' : '') . '>'
                . self::escape($theme->name . ' · ' . $theme->key) . '</option>';
        }

        $history = '';
        if ($snapshot !== null) {
            foreach ($snapshot->history as $revision) {
                $id = $revision->revisionId->value();
                $state = [];
                if ($snapshot->theme->stagingRevisionId?->equals($revision->revisionId)) {
                    $state[] = 'staging';
                }
                if ($snapshot->theme->publishedRevisionId?->equals($revision->revisionId)) {
                    $state[] = 'published';
                }

                $history .= '<article class="theme-revision"><div class="theme-revision-copy"><div class="theme-revision-head"><code>'
                    . self::escape($id) . '</code><span>' . self::escape($state === [] ? 'historical' : implode(' · ', $state))
                    . '</span></div><small>' . self::escape($revision->createdAt->format(DATE_ATOM))
                    . ' · actor ' . self::escape($revision->createdBy->value()) . '</small></div>';

                if ($advanced && !$snapshot->theme->stagingRevisionId?->equals($revision->revisionId)) {
                    $history .= '<form method="post" action="' . $action . '">'
                        . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
                        . '<input type="hidden" name="action" value="rollback">'
                        . '<input type="hidden" name="theme_key" value="' . self::escape($snapshot->theme->key) . '">'
                        . '<input type="hidden" name="revision_id" value="' . self::escape($id) . '">'
                        . '<button type="submit">Staging’e al</button></form>';
                }
                $history .= '</article>';
            }
        }

        $diffHtml = '';
        foreach ($diff as $entry) {
            $diffHtml .= '<tr><td><code>' . self::escape($entry->path) . '</code></td><td><pre>'
                . self::escape($entry->before ?? '∅') . '</pre></td><td><pre>'
                . self::escape($entry->after ?? '∅') . '</pre></td></tr>';
        }
        if ($diffHtml !== '') {
            $diffHtml = '<section class="theme-diff"><div class="theme-section-head"><div><h2>Revision diff</h2>'
                . '<p>Template, phrase/language, CSS ve JavaScript revision farkları.</p></div><span>'
                . count($diff) . '</span></div><div class="theme-diff-scroll"><table><thead><tr><th>Path</th><th>Önce</th><th>Sonra</th></tr></thead><tbody>'
                . $diffHtml . '</tbody></table></div></section>';
        }

        $revisionOptions = '';
        if ($snapshot !== null) {
            foreach ($snapshot->history as $revision) {
                $id = $revision->revisionId->value();
                $revisionOptions .= '<option value="' . self::escape($id) . '">' . self::escape($id) . '</option>';
            }
        }
        $diffForm = $snapshot === null || count($snapshot->history) < 2
            ? ''
            : '<form method="get" action="' . $action . '" class="theme-diff-form">'
                . '<input type="hidden" name="theme" value="' . self::escape($snapshot->theme->key) . '">'
                . '<label>Sol revision<select name="left" required>' . $revisionOptions . '</select></label>'
                . '<label>Sağ revision<select name="right" required>' . $revisionOptions . '</select></label>'
                . '<button type="submit">Diff göster</button></form>';

        $publish = '';
        if ($snapshot?->staging !== null && $advanced) {
            $publish = '<form method="post" action="' . $action . '" class="theme-publish">'
                . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
                . '<input type="hidden" name="action" value="publish">'
                . '<input type="hidden" name="theme_key" value="' . self::escape($snapshot->theme->key) . '">'
                . '<input type="hidden" name="revision_id" value="' . self::escape($snapshot->staging->revisionId->value()) . '">'
                . '<button type="submit">Staging revision’ı yayınla</button></form>';
        }

        $advancedAttrs = $advanced ? '' : ' readonly aria-readonly="true"';
        $advancedNote = $advanced
            ? '<p class="muted">Custom CSS/JS bu revision ile versionlanır ve yayın sırasında same-origin asset cache’e derlenir.</p>'
            : '<p class="muted">Custom CSS/JS ve publish/rollback için appearance.advanced yetkisi gerekir.</p>';

        $parentLabel = 'Base theme';
        if ($selected?->parentThemeId !== null) {
            $parent = $themeById[$selected->parentThemeId->value()] ?? null;
            $parentLabel = $parent instanceof ThemeDefinition
                ? $parent->name . ' · ' . $parent->key
                : $selected->parentThemeId->value();
        }

        $languageSummary = [];
        foreach ($phrases as $locale => $entries) {
            $languageSummary[] = $locale . ' (' . count($entries) . ')';
        }

        $overview = '<section class="theme-overview" aria-label="Tema revision özeti">'
            . self::stat('Temalar', count($themes), 'Kayıtlı theme tanımı')
            . self::stat('Revision', count($snapshot?->history ?? []), $snapshot?->published === null ? 'Published yok' : 'Published mevcut')
            . self::stat('Templates', count($templates), 'Staging payload')
            . self::stat('Languages', count($phrases), $phraseCount . ' phrase')
            . '</section>';

        $context = $selected === null
            ? '<div class="theme-context-empty"><strong>Yeni tema modu</strong><span>Stable key, parent ve ilk staging payload’ını tanımla.</span></div>'
            : '<div class="theme-context-grid">'
                . self::fact('Theme key', $selected->key)
                . self::fact('Parent', $parentLabel)
                . self::fact('Staging', $selected->stagingRevisionId?->value() ?? '—')
                . self::fact('Published', $selected->publishedRevisionId?->value() ?? '—')
                . self::fact('Updated', $selected->updatedAt->format('Y-m-d H:i:s') . ' UTC')
                . self::fact('Languages', $languageSummary === [] ? 'Yok' : implode(', ', $languageSummary))
                . '</div>';

        $editor = '<section class="theme-editor"><div class="theme-section-head"><div><h2>'
            . self::escape($selected?->name ?? 'Yeni Tema') . '</h2><p>Staging revision verisini düzenle; yayınlama ayrı ve explicit aksiyondur.</p></div>'
            . '<span>' . ($advanced ? 'advanced' : 'standard') . '</span></div>'
            . '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="action" value="stage">'
            . '<details class="theme-editor-group" open><summary>Tema kimliği ve inheritance</summary><div class="theme-editor-group-body">'
            . '<div class="theme-field-grid"><label>Theme key<input name="theme_key" maxlength="64" required value="' . self::escape($selected?->key ?? '') . '"'
            . ($selected === null ? '' : ' readonly') . '></label>'
            . '<label>Ad<input name="name" maxlength="120" required value="' . self::escape($selected?->name ?? '') . '"></label>'
            . '<label>Parent theme<select name="parent_key">' . $parentOptions . '</select></label></div></div></details>'
            . '<details class="theme-editor-group" open><summary>Templates ve languages / phrases</summary><div class="theme-editor-group-body theme-code-grid">'
            . '<label>Templates JSON <small>' . count($templates) . ' template</small><textarea name="templates_json" required>' . self::escape($templatesJson) . '</textarea></label>'
            . '<label>Phrases JSON <small>' . count($phrases) . ' language · ' . $phraseCount . ' phrase</small><textarea name="phrases_json" required>' . self::escape($phrasesJson) . '</textarea></label>'
            . '</div></details>'
            . '<details class="theme-editor-group"><summary>Advanced CSS / JavaScript</summary><div class="theme-editor-group-body theme-code-grid">'
            . '<label>Custom CSS<textarea name="custom_css"' . $advancedAttrs . '>' . self::escape($staging?->payload->customCss ?? '') . '</textarea></label>'
            . '<label>Custom JavaScript<textarea name="custom_js"' . $advancedAttrs . '>' . self::escape($staging?->payload->customJs ?? '') . '</textarea></label>'
            . $advancedNote . '</div></details>'
            . '<div class="theme-actions"><button type="submit">Staging revision kaydet</button></div></form>'
            . $publish . '</section>';

        $historyPanel = $snapshot === null
            ? ''
            : '<section class="theme-history"><div class="theme-section-head"><div><h2>Revision geçmişi</h2>'
                . '<p>Immutable revision zinciri; staging/published pointer’ları ayrı tutulur.</p></div><span>'
                . count($snapshot->history) . '</span></div>' . $history . $diffForm . '</section>';

        return '<section class="theme-admin theme-admin--dense">'
            . '<aside class="theme-sidebar"><div class="theme-sidebar-head"><div><span>APPEARANCE</span><h1>Temalar</h1></div><strong>'
            . count($themes) . '</strong></div><nav class="theme-directory" aria-label="Tema dizini">' . $themeList . '</nav>'
            . '<div class="theme-sidebar-links"><a href="' . self::escape($basePath->prepend('/admin/appearance')) . '">Appearance guide</a>'
            . '<a href="' . self::escape($basePath->prepend('/admin/appearance/layout')) . '">Layout Builder</a></div></aside>'
            . '<div class="theme-main">' . $notice . $overview
            . '<section class="theme-context">' . $context . '</section>'
            . '<div class="theme-workspace-grid">' . $editor . $historyPanel . '</div>'
            . $diffHtml . '</div></section>';
    }

    private static function stat(string $label, int $value, string $detail): string
    {
        return '<article class="theme-stat"><span>' . self::escape($label) . '</span><strong>' . $value
            . '</strong><small>' . self::escape($detail) . '</small></article>';
    }

    private static function fact(string $label, string $value): string
    {
        return '<div class="theme-fact"><span>' . self::escape($label) . '</span><strong>' . self::escape($value) . '</strong></div>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
