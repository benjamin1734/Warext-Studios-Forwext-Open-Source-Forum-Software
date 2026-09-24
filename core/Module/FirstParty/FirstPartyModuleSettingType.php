<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

enum FirstPartyModuleSettingType: string
{
    case Flag = 'flag';
    case Integer = 'integer';
    case String = 'string';
}
