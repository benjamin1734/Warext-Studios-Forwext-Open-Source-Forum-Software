<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Navigation;

enum NavigationAudience: string
{
    case Public = 'public';
    case Member = 'member';
}
