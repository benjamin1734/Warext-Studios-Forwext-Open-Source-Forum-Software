<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

enum ProfileActivityScope: string
{
    case Everyone = 'everyone';
    case Followers = 'followers';
    case OwnerOnly = 'owner_only';
}
