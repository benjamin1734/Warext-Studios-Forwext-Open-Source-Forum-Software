<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Widget;

interface Widget
{
    public function key(): string;

    public function slot(): string;

    public function order(): int;

    public function cacheTtlSeconds(): int;

    public function render(WidgetContext $context): string;
}
