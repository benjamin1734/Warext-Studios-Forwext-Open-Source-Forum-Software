<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

interface InstalledVersionStore
{
    public function current(): ?SemanticVersion;

    public function write(SemanticVersion $version): void;
}
