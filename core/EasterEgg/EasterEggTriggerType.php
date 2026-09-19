<?php

declare(strict_types=1);

namespace Forwext\Core\EasterEgg;

enum EasterEggTriggerType: string
{
    case Automatic = 'automatic';
    case QueryToken = 'query_token';
}
