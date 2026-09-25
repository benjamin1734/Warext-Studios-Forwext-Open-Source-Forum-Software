<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli\Command;

use Forwext\Core\Addon\AddonId;
use Forwext\Tools\Addon\AddonCompatibilityChecker;
use Forwext\Tools\Cli\CliCommand;
use Forwext\Tools\Cli\CliResult;

final readonly class AddonCheckCommand implements CliCommand
{
    public function __construct(private AddonCompatibilityChecker $checker)
    {
    }

    public function name(): string
    {
        return 'addon:check';
    }

    public function description(): string
    {
        return 'Run manifest, PHP and security compatibility checks.';
    }

    public function execute(array $arguments): CliResult
    {
        if (count($arguments) !== 1) {
            return CliResult::failure("Usage: addon:check Vendor/AddOn\n", 2);
        }
        $report = $this->checker->check(AddonId::fromString($arguments[0]));
        $lines = [];
        foreach ($report->warnings as $warning) {
            $lines[] = 'WARN: ' . $warning;
        }
        foreach ($report->errors as $error) {
            $lines[] = 'ERROR: ' . $error;
        }
        if (!$report->compatible()) {
            return CliResult::failure(implode("\n", $lines) . "\n", 4);
        }
        $lines[] = 'Compatible.';
        return CliResult::success(implode("\n", $lines) . "\n");
    }
}
