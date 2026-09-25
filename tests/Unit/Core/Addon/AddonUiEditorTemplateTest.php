<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use Forwext\App\Web\Editor\RichEditorView;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\Ui\AddonUiRegistration;
use Forwext\Core\Addon\Ui\AddonUiTemplateDefinition;
use Forwext\Core\Addon\Ui\AddonUiTemplateRegistry;
use Forwext\Core\Forum\Editor\EditorLimits;
use Forwext\Core\Forum\Editor\EditorSurface;
use Forwext\Core\Forum\Editor\Extension\EditorExtensionRegistry;
use Forwext\Core\Forum\Editor\Extension\EditorToolbarExtension;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonUiEditorTemplateTest extends TestCase
{
    public function testNamespacedEditorExtensionRendersThroughTheSharedEditorToolbar(): void
    {
        $registration = new AddonUiRegistration(AddonId::fromString('Acme/Demo'));
        $registration->editorExtension(new EditorToolbarExtension(
            'addon.acme.demo.editor.note',
            'Not',
            '[note]',
            '[/note]',
            600,
            [EditorSurface::Post],
        ));

        $registry = new EditorExtensionRegistry();
        $registration->registerEditorExtensions($registry);

        $html = RichEditorView::render(
            'message',
            '',
            EditorSurface::Post,
            new EditorLimits(),
            new BasePath(),
            'post-editor',
            $registry,
        );

        self::assertStringContainsString('data-fx-editor-extension-key="addon.acme.demo.editor.note"', $html);
        self::assertStringContainsString('data-open="[note]"', $html);
        self::assertStringContainsString('data-close="[/note]"', $html);
        self::assertStringContainsString('>Not</button>', $html);
    }

    public function testEditorExtensionSurfaceAndNamespaceAreEnforced(): void
    {
        $registration = new AddonUiRegistration(AddonId::fromString('Acme/Demo'));
        $this->expectException(InvalidArgumentException::class);
        $registration->editorExtension(new EditorToolbarExtension(
            'other.editor.note',
            'Not',
            '[note]',
            '[/note]',
        ));
    }

    public function testAddonTemplateUsesExistingEscapedThemeTemplateSyntax(): void
    {
        $registration = new AddonUiRegistration(AddonId::fromString('Acme/Demo'));
        $registration->template(new AddonUiTemplateDefinition(
            'addon.acme.demo.card',
            '<article>{{ title }}</article>',
        ));

        $registry = new AddonUiTemplateRegistry();
        $registration->registerTemplates($registry);

        self::assertSame(
            '<article>&lt;script&gt;alert(1)&lt;/script&gt;</article>',
            $registry->render('addon.acme.demo.card', ['title'=>'<script>alert(1)</script>']),
        );
    }
}
