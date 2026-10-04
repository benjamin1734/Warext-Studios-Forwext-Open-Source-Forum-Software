<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Navigation;

enum NavigationPlacement: string
{
    case Primary = 'primary';
    case More = 'more';
    case Utility = 'utility';
}
