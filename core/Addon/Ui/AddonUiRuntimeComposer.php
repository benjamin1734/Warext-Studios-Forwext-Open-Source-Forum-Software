<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use Forwext\Core\Forum\Editor\Extension\EditorExtensionRegistry;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use Forwext\Core\Ui\Layout\UiSlotRegistry;
use Forwext\Core\Ui\Navigation\NavigationRegistry;
use Forwext\Core\Ui\Widget\WidgetRegistry;

final readonly class AddonUiRuntimeComposer
{
    public function __construct(private AddonUiAssetCompiler $assets)
    {
    }

    public function compose(
        AddonUiRegistry $enabled,
        ?DesignTokenCatalog $baseTokens = null,
    ): AddonUiRuntimeComposition {
        $registrations = $enabled->all();
        $slots = UiSlotRegistry::withCoreDefaults($registrations);
        $widgets = WidgetRegistry::withCoreDefaults($slots, $registrations);
        $navigation = NavigationRegistry::withCoreDefaults($registrations);

        $tokens = $baseTokens ?? DesignTokenCatalog::coreDefaults();
        $editor = new EditorExtensionRegistry();
        $templates = new AddonUiTemplateRegistry();
        foreach ($registrations as $registration) {
            $tokens = $registration->extendDesignTokens($tokens);
            $registration->registerEditorExtensions($editor);
            $registration->registerTemplates($templates);
        }

        return new AddonUiRuntimeComposition(
            $slots,
            $widgets,
            $navigation,
            $tokens,
            $editor,
            $templates,
            $this->assets->compileRegistry($enabled),
        );
    }
}
