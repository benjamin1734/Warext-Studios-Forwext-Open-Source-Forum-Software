<?php

declare(strict_types=1);

namespace Forwext\Core\Logging;

interface StructuredLogger
{
    /** @param array<string, mixed> $context */
    public function log(LogLevel $level, string $message, array $context = []): void;
}
