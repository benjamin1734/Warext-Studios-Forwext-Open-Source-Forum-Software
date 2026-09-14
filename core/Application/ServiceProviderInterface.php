<?php

declare(strict_types=1);

namespace Forwext\Core\Application;

use Forwext\Core\Container\Container;

interface ServiceProviderInterface
{
    public function register(Container $container): void;

    public function boot(Container $container): void;
}
