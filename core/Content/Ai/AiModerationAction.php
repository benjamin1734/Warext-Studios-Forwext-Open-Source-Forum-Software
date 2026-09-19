<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

enum AiModerationAction: string
{
    case Allow = 'allow';
    case Flag = 'flag';
    case Queue = 'queue';
    case Reject = 'reject';
}
