<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Navigation;

interface NavigationContributor
{
    public function registerNavigation(NavigationRegistry $registry): void;
}
