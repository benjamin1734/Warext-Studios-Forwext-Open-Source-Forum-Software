<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance;

enum ComponentAppearanceTarget: string
{
    case Header = 'header';
    case Navigation = 'navigation';
    case Footer = 'footer';
    case Forum = 'forum';
    case Thread = 'thread';
    case Post = 'post';
    case Profile = 'profile';
    case Button = 'button';
    case Input = 'input';
    case Modal = 'modal';
    case Badge = 'badge';
    case RoleBanner = 'role-banner';
    case Editor = 'editor';
    case Table = 'table';
    case Alert = 'alert';
}
