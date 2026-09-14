<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

enum UserCustomFieldType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Json = 'json';
}
