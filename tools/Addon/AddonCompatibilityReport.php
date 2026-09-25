<?php

declare(strict_types=1);

namespace Forwext\Tools\Addon;

final readonly class AddonCompatibilityReport
{
    /** @param list<string> $errors @param list<string> $warnings */
    public function __construct(public array $errors, public array $warnings)
    {
    }

    public function compatible(): bool
    {
        return $this->errors === [];
    }
}
