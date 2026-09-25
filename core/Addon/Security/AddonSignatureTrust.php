<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

enum AddonSignatureTrust: string
{
    case Unsigned = 'unsigned';
    case Signed = 'signed';
    case Official = 'official';
}
