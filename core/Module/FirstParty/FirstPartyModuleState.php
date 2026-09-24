<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

enum FirstPartyModuleState: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Uninstalled = 'uninstalled';
}
