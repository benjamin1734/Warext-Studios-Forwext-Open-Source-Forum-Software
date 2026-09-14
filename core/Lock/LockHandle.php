<?php

declare(strict_types=1);

namespace Forwext\Core\Lock;

interface LockHandle
{
    public function name(): string;

    public function release(): void;
}
