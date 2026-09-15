<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

enum PollVoterVisibility: string
{
    case Open = 'open';
    case Secret = 'secret';
}
