<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

enum ProfileMediaKind: string
{
    case Avatar = 'avatar';
    case Banner = 'banner';
}
