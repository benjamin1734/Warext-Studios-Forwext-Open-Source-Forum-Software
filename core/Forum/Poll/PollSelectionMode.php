<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Poll;

enum PollSelectionMode: string
{
    case Single = 'single';
    case Multiple = 'multiple';
}
