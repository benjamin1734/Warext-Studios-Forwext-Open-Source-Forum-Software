<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Layout\Builder;

enum LayoutRevisionSource: string
{
    case Editor = 'editor';
    case Import = 'import';
}
