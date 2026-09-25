<?php

declare(strict_types=1);

namespace Warext\HelloWorld\Widget;

use Forwext\Core\Ui\Widget\Widget;
use Forwext\Core\Ui\Widget\WidgetContext;

final readonly class HelloWidget implements Widget
{
    public function key(): string
    {
        return 'addon.warext.helloworld.widget.hello';
    }

    public function slot(): string
    {
        return 'footer.before';
    }

    public function order(): int
    {
        return 500;
    }

    public function cacheTtlSeconds(): int
    {
        return 300;
    }

    public function render(WidgetContext $context): string
    {
        return '<p class="addon-well-known-example">Hello from Warext/HelloWorld.</p>';
    }
}
