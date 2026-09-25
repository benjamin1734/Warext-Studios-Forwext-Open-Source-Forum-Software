<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli\Command;

use Forwext\Tools\Addon\DeveloperAddonPackageManager;
use Forwext\Tools\Cli\CliCommand;
use Forwext\Tools\Cli\CliResult;
use Forwext\Tools\Dev\DeveloperMode;

final readonly class AddonWorkspaceCommand implements CliCommand
{
    public function __construct(
        private DeveloperMode $mode,
        private DeveloperAddonPackageManager $packages,
        private string $operation,
    ) {
    }

    public function name(): string
    {
        return 'addon:' . $this->operation;
    }

    public function description(): string
    {
        return ucfirst($this->operation) . ' an add-on source tree into the developer workspace.';
    }

    public function execute(array $arguments): CliResult
    {
        if (count($arguments) !== 1) {
            return CliResult::failure('Usage: ' . $this->name() . " /path/to/addon\n", 2);
        }
        if (!$this->mode->isEnabled()) {
            return CliResult::failure("Developer mode is disabled. Run dev:enable first.\n", 3);
        }
        $id = match ($this->operation) {
            'install' => $this->packages->install($arguments[0]),
            'upgrade' => $this->packages->upgrade($arguments[0]),
            default => throw new \InvalidArgumentException('Unsupported workspace package operation.'),
        };
        return CliResult::success(
            ucfirst($this->operation) . 'ed ' . $id
            . " in the development workspace. Runtime lifecycle state was not bypassed.\n",
        );
    }
}
