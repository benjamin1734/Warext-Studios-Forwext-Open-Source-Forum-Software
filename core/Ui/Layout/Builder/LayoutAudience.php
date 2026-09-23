<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

enum LayoutAudience: string
{
    case All = 'all';
    case Guest = 'guest';
    case Member = 'member';
}
