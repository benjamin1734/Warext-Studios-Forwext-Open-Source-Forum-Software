<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

enum AddonSettingType: string
{
    case Flag = 'flag';
    case Integer = 'integer';
    case String = 'string';
}
