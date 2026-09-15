<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

enum PollResultVisibility: string
{
    case Always = 'always';
    case AfterVote = 'after_vote';
    case AfterClose = 'after_close';
}
