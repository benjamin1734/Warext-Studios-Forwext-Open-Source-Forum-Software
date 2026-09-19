<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Editor;

use Forwext\App\Web\Editor\RichEditorView;
use Forwext\Core\Forum\Editor\EditorLimits;
use Forwext\Core\Forum\Editor\EditorSurface;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class RichEditorViewTest extends TestCase
{
    public function testEditorMarkupCarriesSocialToolsPreviewCountersAndBasePathAwareEndpoints(): void
    {
        $html = RichEditorView::render(
            'body',
            '<unsafe> [b]source[/b]',
            EditorSurface::Thread,
            new EditorLimits(minCharacters: 1, maxCharacters: 500, maxBytes: 800, minWords: 0, maxWords: 100),
            new BasePath('/forum'),
            'thread-editor',
        );

        self::assertStringContainsString('data-fx-editor', $html);
        self::assertStringContainsString('data-preview-url="/forum/editor/preview"', $html);
        self::assertStringContainsString('data-mention-url="/forum/editor/mention"', $html);
        self::assertStringContainsString('data-quote-url="/forum/editor/quote"', $html);
        self::assertStringContainsString('data-link-preview-url="/forum/editor/link-preview"', $html);
        self::assertStringContainsString('data-spellcheck-url="/forum/editor/spellcheck"', $html);
        self::assertStringContainsString('data-spellcheck-language="tr-tr"', $html);
        self::assertStringContainsString('data-fx-editor-characters', $html);
        self::assertStringContainsString('data-fx-editor-words', $html);
        self::assertStringContainsString('data-fx-editor-bytes', $html);
        self::assertStringContainsString('data-fx-editor-preview-button', $html);
        self::assertStringContainsString('data-fx-editor-command="mention"', $html);
        self::assertStringContainsString('data-fx-editor-command="quote-post"', $html);
        self::assertStringContainsString('data-fx-editor-command="link-preview"', $html);
        self::assertStringContainsString('data-fx-editor-command="emoji"', $html);
        self::assertStringContainsString('data-fx-editor-mention-menu', $html);
        self::assertStringContainsString('data-fx-editor-emoji-palette', $html);
        self::assertStringContainsString('data-fx-editor-spellcheck-button', $html);
        self::assertStringContainsString('data-fx-editor-spellcheck', $html);
        self::assertStringContainsString('href="/forum/account/spellcheck-dictionary"', $html);
        self::assertStringContainsString('&lt;unsafe&gt; [b]source[/b]', $html);
        self::assertStringNotContainsString('<unsafe>', $html);
    }

    public function testAssetsAreBasePathAwareAndUseExternalFiles(): void
    {
        $html = RichEditorView::assets(new BasePath('/forum'));

        self::assertStringContainsString('href="/forum/assets/rich-editor.css"', $html);
        self::assertStringContainsString('src="/forum/assets/rich-editor.js"', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
