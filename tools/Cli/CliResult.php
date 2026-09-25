<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli;

final readonly class CliResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout = '',
        public string $stderr = '',
    ) {
    }

    public static function success(string $stdout = ''): self
    {
        return new self(0, $stdout);
    }

    public static function failure(string $stderr, int $exitCode = 1): self
    {
        return new self($exitCode, '', $stderr);
    }
}
