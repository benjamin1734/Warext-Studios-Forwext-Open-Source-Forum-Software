<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance;

enum ComponentAppearanceProperty: string
{
    case Background = 'background';
    case SecondaryBackground = 'secondary-background';
    case Text = 'text';
    case MutedText = 'muted-text';
    case ContrastText = 'contrast-text';
    case BorderColor = 'border-color';
    case BorderWidth = 'border-width';
    case Radius = 'radius';
    case Shadow = 'shadow';
    case Accent = 'accent';
    case Spacing = 'spacing';
    case MotionDuration = 'motion-duration';
}
