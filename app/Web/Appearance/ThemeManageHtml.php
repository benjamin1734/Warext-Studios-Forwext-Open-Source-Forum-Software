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

        $themeList = '<a class="theme-list-item" href="' . $action . '">+ Yeni tema</a>';
        foreach ($themes as $theme) {
            $themeList .= '<a class="theme-list-item" href="' . $action . '?theme='
                . rawurlencode($theme->key) . '"><strong>' . self::escape($theme->name) . '</strong>'
                . '<small>' . self::escape($theme->key) . '</small></a>';
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

                $history .= '<article class="theme-revision"><div><code>' . self::escape($id) . '</code>'
                    . '<small>' . self::escape($revision->createdAt->format(DATE_ATOM))
                    . ($state === [] ? '' : ' · ' . implode(', ', $state)) . '</small></div>';

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
            $diffHtml = '<div class="theme-diff"><h2>Revision diff</h2><table><thead><tr><th>Path</th><th>Önce</th><th>Sonra</th></tr></thead><tbody>'
                . $diffHtml . '</tbody></table></div>';
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

        return '<section class="theme-admin">'
            . '<aside class="theme-sidebar"><h2>Temalar</h2>' . $themeList . '</aside>'
            . '<div class="theme-main"><section class="theme-editor"><h1>'
            . self::escape($selected?->name ?? 'Yeni Tema') . '</h1>'
            . '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">'
            . '<input type="hidden" name="action" value="stage">'
            . '<label>Theme key<input name="theme_key" maxlength="64" required value="' . self::escape($selected?->key ?? '') . '"'
            . ($selected === null ? '' : ' readonly') . '></label>'
            . '<label>Ad<input name="name" maxlength="120" required value="' . self::escape($selected?->name ?? '') . '"></label>'
            . '<label>Parent theme<select name="parent_key">' . $parentOptions . '</select></label>'
            . '<label>Templates JSON<textarea name="templates_json" required>' . self::escape($templatesJson) . '</textarea></label>'
            . '<label>Phrases JSON<textarea name="phrases_json" required>' . self::escape($phrasesJson) . '</textarea></label>'
            . '<label>Custom CSS<textarea name="custom_css"' . $advancedAttrs . '>' . self::escape($staging?->payload->customCss ?? '') . '</textarea></label>'
            . '<label>Custom JavaScript<textarea name="custom_js"' . $advancedAttrs . '>' . self::escape($staging?->payload->customJs ?? '') . '</textarea></label>'
            . $advancedNote
            . '<div class="theme-actions"><button type="submit">Staging revision kaydet</button></div></form>'
            . $publish . '</section>'
            . ($snapshot === null ? '' : '<section class="theme-history"><h2>Revision geçmişi</h2>' . $history . $diffForm . '</section>')
            . $diffHtml . '</div></section>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
