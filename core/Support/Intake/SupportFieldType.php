<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

enum SupportFieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
    case Checkbox = 'checkbox';
}
