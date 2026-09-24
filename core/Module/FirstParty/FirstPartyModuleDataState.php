<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

enum FirstPartyModuleDataState: string
{
    case Retained = 'retained';
    case PurgePending = 'purge_pending';
    case Purged = 'purged';
}
