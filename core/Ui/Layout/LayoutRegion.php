<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout;

enum LayoutRegion: string
{
    case Header = 'header';
    case Main = 'main';
    case Sidebar = 'sidebar';
    case Footer = 'footer';
    case Page = 'page';
}
