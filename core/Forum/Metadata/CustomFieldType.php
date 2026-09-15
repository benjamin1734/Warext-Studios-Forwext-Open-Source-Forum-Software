<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

enum CustomFieldType: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Choice = 'choice';
}
