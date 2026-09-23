<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Widget;

final readonly class CoreBrandFooterWidget implements Widget
{
    public function key(): string
    {
        return 'core.brand-footer';
    }

    public function slot(): string
    {
        return 'footer.after';
    }

    public function order(): int
    {
        return 1000;
    }

    public function cacheTtlSeconds(): int
    {
        return 3600;
    }

    public function render(WidgetContext $context): string
    {
        return '<div class="core-brand-footer">Forwext · Warext Studios</div>';
    }
}
