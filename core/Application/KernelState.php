<?php

declare(strict_types=1);

namespace Forwext\Core\Application;

enum KernelState: string
{
    case Created = 'created';
    case Booting = 'booting';
    case Booted = 'booted';
    case Failed = 'failed';
    case Terminated = 'terminated';
}
