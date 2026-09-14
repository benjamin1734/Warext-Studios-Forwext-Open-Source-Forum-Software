<?php

declare(strict_types=1);

namespace Forwext\Core\Storage;

enum StorageVisibility: string
{
    case Private = 'private';
    case Public = 'public';
}
