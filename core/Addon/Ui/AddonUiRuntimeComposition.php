<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Ui;

use Forwext\Core\Forum\Editor\Extension\EditorExtensionRegistry;
use Forwext\Core\Ui\DesignToken\DesignTokenCatalog;
use Forwext\Core\Ui\Layout\UiSlotRegistry;
use Forwext\Core\Ui\Navigation\NavigationRegistry;
use Forwext\Core\Ui\Widget\WidgetRegistry;

final readonly class AddonUiRuntimeComposition
{
    public function __construct(
        public UiSlotRegistry $slots,
        public WidgetRegistry $widgets,
        public NavigationRegistry $navigation,
        public DesignTokenCatalog $designTokens,
        public EditorExtensionRegistry $editorExtensions,
        public AddonUiTemplateRegistry $templates,
        public AddonUiAssetManifest $assets,
    ) {
    }
}
