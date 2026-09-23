<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\DesignToken;

enum DesignTokenCategory: string
{
    case Color = 'color';
    case Typography = 'typography';
    case Spacing = 'spacing';
    case Radius = 'radius';
    case Border = 'border';
    case Shadow = 'shadow';
    case Motion = 'motion';
    case Semantic = 'semantic';
}
