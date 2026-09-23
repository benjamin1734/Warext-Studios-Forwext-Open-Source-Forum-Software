<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Widget;

interface WidgetContributor
{
    public function registerWidgets(WidgetRegistry $registry): void;
}
