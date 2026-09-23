<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Responsive;

enum ResponsiveTarget: string
{
    case Site = 'site';
    case Header = 'header';
    case Navigation = 'navigation';
    case Main = 'main';
    case Forum = 'forum';
    case Profile = 'profile';
    case Sidebar = 'sidebar';
    case Editor = 'editor';
    case Table = 'table';
}
