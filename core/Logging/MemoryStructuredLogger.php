<?php

declare(strict_types=1);

namespace Forwext\Core\Logging;

final class MemoryStructuredLogger implements StructuredLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level->value,
            'message' => $message,
            'context' => $context,
        ];
    }
}
