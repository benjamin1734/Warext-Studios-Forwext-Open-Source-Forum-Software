<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

enum AddonSignaturePolicy: string
{
    case Optional = 'optional';
    case RequireSigned = 'signed';
    case RequireOfficial = 'official';
}
