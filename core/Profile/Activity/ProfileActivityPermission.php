<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use Forwext\Core\Domain\Access\Permission\PermissionKey;

enum ProfileActivityPermission: string
{
    case View = 'profile.post.view';
    case Create = 'profile.post.create';
    case Comment = 'profile.post.comment';
    case React = 'profile.post.react';
    case Moderate = 'profile.post.moderate';

    public function key(): PermissionKey { return PermissionKey::fromString($this->value); }
}
