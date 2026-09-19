<?php

declare(strict_types=1);

namespace Forwext\Core\EasterEgg;

enum EasterEggAnimation: string
{
    case None = 'none';
    case Pulse = 'pulse';
    case Glow = 'glow';
    case Confetti = 'confetti';
}
