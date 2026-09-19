<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

enum PortfolioCommentState: string
{
    case Pending = 'pending';
    case Visible = 'visible';
    case Rejected = 'rejected';
}
