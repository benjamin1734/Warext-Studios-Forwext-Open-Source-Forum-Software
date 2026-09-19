<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

enum PortfolioState: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';
}
