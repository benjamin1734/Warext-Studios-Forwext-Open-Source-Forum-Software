<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli\Command;

use Forwext\Tools\Cli\CliCommand;
use Forwext\Tools\Cli\CliResult;
use Forwext\Tools\Dev\DeveloperMode;

final readonly class DevModeCommand implements CliCommand
{
    public function __construct(private DeveloperMode $mode, private string $operation)
    {
    }

    public function name(): string
    {
        return 'dev:' . $this->operation;
    }

    public function description(): string
    {
        return ucfirst($this->operation) . ' developer-workspace mutation mode.';
    }

    public function execute(array $arguments): CliResult
    {
        if ($arguments !== []) {
            return CliResult::failure('Usage: ' . $this->name() . "\n", 2);
        }
        return match ($this->operation) {
            'enable' => $this->enable(),
            'disable' => $this->disable(),
            'status' => CliResult::success('Developer mode: ' . ($this->mode->isEnabled() ? 'enabled' : 'disabled') . "\n"),
            default => CliResult::failure("Unsupported developer mode operation.\n", 2),
        };
    }

    private function enable(): CliResult
    {
        $this->mode->enable();
        return CliResult::success("Developer mode enabled for CLI workspace mutations only. Runtime permissions are unchanged.\n");
    }

    private function disable(): CliResult
    {
        $this->mode->disable();
        return CliResult::success("Developer mode disabled.\n");
    }
}
