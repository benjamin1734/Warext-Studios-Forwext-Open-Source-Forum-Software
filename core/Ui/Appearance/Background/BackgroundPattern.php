<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Background;

enum BackgroundPattern: string
{
    case Dots = 'dots';
    case Grid = 'grid';
    case Diagonal = 'diagonal';
    case Checker = 'checker';
}
