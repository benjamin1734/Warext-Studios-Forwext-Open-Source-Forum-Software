<?php

declare(strict_types=1);

namespace Forwext\App\Web\Editor;

use Forwext\Core\Forum\Editor\EditorLimits;
use Forwext\Core\Forum\Editor\EditorSurface;
use Forwext\Core\Forum\Editor\EditorTextMetrics;
use Forwext\Core\Forum\Editor\EmojiCatalog;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final class RichEditorView
{
    public static function assets(BasePath $basePath): string
    {
        $css = self::escape($basePath->prepend('/assets/rich-editor.css'));
        $js = self::escape($basePath->prepend('/assets/rich-editor.js'));
        return '<link rel="stylesheet" href="' . $css . '">'
            . '<script src="' . $js . '" defer></script>';
    }

    public static function render(
        string $fieldName,
        string $initialSource,
        EditorSurface $surface,
        EditorLimits $limits,
        BasePath $basePath,
        string $elementId = 'forwext-editor',
    ): string {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $fieldName) !== 1) {
            throw new InvalidArgumentException('Editor field name is invalid.');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $elementId) !== 1) {
            throw new InvalidArgumentException('Editor element id is invalid.');
        }

        $metrics = EditorTextMetrics::measure($initialSource);
        $maxWords = $limits->maxWords === null ? '' : (string) $limits->maxWords;
        $previewUrl = self::escape($basePath->prepend('/editor/preview'));
        $mentionUrl = self::escape($basePath->prepend('/editor/mention'));
        $quoteUrl = self::escape($basePath->prepend('/editor/quote'));
        $linkPreviewUrl = self::escape($basePath->prepend('/editor/link-preview'));
        $spellcheckUrl = self::escape($basePath->prepend('/editor/spellcheck'));
        $dictionaryUrl = self::escape($basePath->prepend('/account/spellcheck-dictionary'));
        $textareaId = self::escape($elementId . '-source');
        $rootId = self::escape($elementId);

        return '<section id="' . $rootId . '" class="fx-editor" data-fx-editor '
            . 'data-preview-url="' . $previewUrl . '" data-mention-url="' . $mentionUrl . '" '
            . 'data-quote-url="' . $quoteUrl . '" data-link-preview-url="' . $linkPreviewUrl . '" '
            . 'data-spellcheck-url="' . $spellcheckUrl . '" data-spellcheck-language="tr-tr" '
            . 'data-min-characters="' . $limits->minCharacters . '" '
            . 'data-max-characters="' . $limits->maxCharacters . '" '
            . 'data-max-bytes="' . $limits->maxBytes . '" '
            . 'data-min-words="' . $limits->minWords . '" '
            . 'data-max-words="' . self::escape($maxWords) . '" '
            . 'data-surface="' . self::escape($surface->value) . '">'
            . '<label class="fx-editor__label" for="' . $textareaId . '">' . self::escape($surface->label()) . '</label>'
            . '<div class="fx-editor__toolbar" role="toolbar" aria-label="Metin biçimlendirme">'
            . self::button('Kalın', 'wrap', '[b]', '[/b]')
            . self::button('İtalik', 'wrap', '[i]', '[/i]')
            . self::button('Altı çizili', 'wrap', '[u]', '[/u]')
            . self::button('Üstü çizili', 'wrap', '[s]', '[/s]')
            . self::button('Alıntı', 'wrap', '[quote]', '[/quote]')
            . self::button('Kod', 'wrap', '[code]', '[/code]')
            . '<button type="button" data-fx-editor-command="url">Bağlantı</button>'
            . '<button type="button" data-fx-editor-command="link-preview">Link önizle</button>'
            . '<button type="button" data-fx-editor-command="mention">@ Kullanıcı</button>'
            . '<button type="button" data-fx-editor-command="quote-post">Mesaj alıntıla</button>'
            . '<button type="button" data-fx-editor-command="embed">Embed</button>'
            . '<button type="button" data-fx-editor-command="emoji" aria-expanded="false">Emoji</button>'
            . '<button type="button" data-fx-editor-spellcheck-button>Yazımı denetle</button>'
            . '</div>'
            . '<div class="fx-editor__emoji" data-fx-editor-emoji-palette hidden>' . self::emojiButtons() . '</div>'
            . '<textarea id="' . $textareaId . '" name="' . self::escape($fieldName) . '" rows="12" '
            . 'data-fx-editor-source spellcheck="true" autocomplete="off">' . self::escape($initialSource) . '</textarea>'
            . '<div class="fx-editor__mention-menu" data-fx-editor-mention-menu hidden role="listbox" aria-label="Kullanıcı önerileri"></div>'
            . '<div class="fx-editor__link-preview" data-fx-editor-link-preview hidden></div>'
            . '<div class="fx-editor__spellcheck" data-fx-editor-spellcheck hidden aria-live="polite"></div>'
            . '<div class="fx-editor__meta" aria-live="polite">'
            . '<span data-fx-editor-characters>Karakter: ' . $metrics->characters . '</span>'
            . '<span data-fx-editor-words>Kelime: ' . $metrics->words . '</span>'
            . '<span data-fx-editor-bytes>Byte: ' . $metrics->bytes . '</span>'
            . '<span data-fx-editor-limits>Karakter ' . $limits->minCharacters . '–' . $limits->maxCharacters
            . '; byte ≤ ' . $limits->maxBytes
            . ($limits->maxWords === null
                ? '; kelime ≥ ' . $limits->minWords
                : '; kelime ' . $limits->minWords . '–' . $limits->maxWords)
            . '</span></div>'
            . '<div class="fx-editor__actions"><button type="button" data-fx-editor-preview-button>Önizle</button>'
            . '<a class="fx-editor__dictionary-link" href="' . $dictionaryUrl . '">Sözlük</a>'
            . '<span class="fx-editor__status" data-fx-editor-status aria-live="polite"></span></div>'
            . '<div class="fx-editor__preview" data-fx-editor-preview hidden></div>'
            . '</section>';
    }

    private static function emojiButtons(): string
    {
        $html = '';
        foreach (EmojiCatalog::all() as $key => $entry) {
            $html .= '<button type="button" data-fx-editor-emoji="' . self::escape($key) . '" '
                . 'title="' . self::escape($entry['label']) . '" aria-label="' . self::escape($entry['label']) . '">'
                . $entry['emoji'] . '</button>';
        }
        return $html;
    }

    private static function button(string $label, string $command, string $open, string $close): string
    {
        return '<button type="button" data-fx-editor-command="' . self::escape($command) . '" '
            . 'data-open="' . self::escape($open) . '" data-close="' . self::escape($close) . '">'
            . self::escape($label) . '</button>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
