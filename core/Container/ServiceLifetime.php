<?php

declare(strict_types=1);

namespace Forwext\Core\Container;

enum ServiceLifetime: string
{
    case Transient = 'transient';
    case Singleton = 'singleton';
}
