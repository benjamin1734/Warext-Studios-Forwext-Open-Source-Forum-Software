<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli;

interface CliCommand
{
    public function name(): string;

    public function description(): string;

    /** @param list<string> $arguments */
    public function execute(array $arguments): CliResult;
}
