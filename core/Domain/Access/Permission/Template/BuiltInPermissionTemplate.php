<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission\Template;

enum BuiltInPermissionTemplate: string
{
    case NewUser = 'new_user';
    case Member = 'member';
    case Verified = 'verified';
    case Moderator = 'moderator';
    case Administrator = 'administrator';
}
