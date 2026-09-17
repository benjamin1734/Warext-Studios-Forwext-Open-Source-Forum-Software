<?php

declare(strict_types=1);

namespace Forwext\App\Web\Seo;

enum FeedFormat: string
{
    case Rss = 'rss';
    case Atom = 'atom';
}
