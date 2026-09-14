<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Cookie;

enum SameSite: string
{
    case Strict = 'Strict';
    case Lax = 'Lax';
    case None = 'None';
}
