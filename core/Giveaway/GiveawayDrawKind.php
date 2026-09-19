<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

enum GiveawayDrawKind: string
{
    case Primary = 'primary';
    case Redraw = 'redraw';
}
