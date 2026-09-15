<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\State;

enum DraftTargetType: string
{
    case NewThread = 'new_thread';
    case Reply = 'reply';
}
