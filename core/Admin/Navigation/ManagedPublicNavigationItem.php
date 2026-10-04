<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Navigation;

use Forwext\Core\Ui\Navigation\NavigationAudience;
use Forwext\Core\Ui\Navigation\NavigationPlacement;

final readonly class ManagedPublicNavigationItem
{
    public function __construct(
        public string $key,
        public string $label,
        public string $path,
        public int $order,
        public NavigationAudience $audience,
        public NavigationPlacement $placement,
        public bool $enabled,
        public bool $custom,
    ) {
    }
}
