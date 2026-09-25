<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli;

use Throwable;

final class CliApplication
{
    /** @var array<string,CliCommand> */
    private array $commands = [];

    /** @param iterable<CliCommand> $commands */
    public function __construct(array|iterable $commands = [])
    {
        foreach ($commands as $command) {
            $this->register($command);
        }
    }

    public function register(CliCommand $command): void
    {
        $name = $command->name();
        if ($name === '' || isset($this->commands[$name])) {
            throw new \InvalidArgumentException('CLI command name is invalid or already registered.');
        }
        $this->commands[$name] = $command;
    }

    /** @param list<string> $argv */
    public function run(array $argv): CliResult
    {
        $commandName = $argv[1] ?? 'help';
        if ($commandName === 'help' || $commandName === '--help' || $commandName === '-h') {
            return CliResult::success($this->help());
        }

        $command = $this->commands[$commandName] ?? null;
        if ($command === null) {
            return CliResult::failure('Unknown command: ' . $commandName . "\n\n" . $this->help(), 2);
        }

        try {
            return $command->execute(array_values(array_slice($argv, 2)));
        } catch (Throwable $exception) {
            return CliResult::failure($exception->getMessage() . "\n");
        }
    }

    private function help(): string
    {
        $commands = $this->commands;
        ksort($commands, SORT_STRING);

        $lines = [
            'Forwext Developer CLI',
            '',
            'Usage: php tools/forwext.php <command> [arguments]',
            '',
            'Commands:',
        ];
        foreach ($commands as $command) {
            $lines[] = '  ' . str_pad($command->name(), 20) . $command->description();
        }

        return implode("\n", $lines) . "\n";
    }
}
