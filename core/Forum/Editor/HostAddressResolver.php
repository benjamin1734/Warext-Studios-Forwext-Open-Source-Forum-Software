<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

interface HostAddressResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
