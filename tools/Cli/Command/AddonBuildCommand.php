<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli\Command;

use Forwext\Core\Addon\AddonId;
use Forwext\Tools\Addon\AddonPackageBuilder;
use Forwext\Tools\Cli\CliCommand;
use Forwext\Tools\Cli\CliResult;

final readonly class AddonBuildCommand implements CliCommand
{
    public function __construct(private AddonPackageBuilder $builder)
    {
    }

    public function name(): string
    {
        return 'addon:build';
    }

    public function description(): string
    {
        return 'Build a deterministic compatibility-checked add-on ZIP.';
    }

    public function execute(array $arguments): CliResult
    {
        if (count($arguments) < 1 || count($arguments) > 2) {
            return CliResult::failure("Usage: addon:build Vendor/AddOn [output.zip]\n", 2);
        }
        $result = $this->builder->build(AddonId::fromString($arguments[0]), $arguments[1] ?? null);
        return CliResult::success(
            "Built {$result->path}\nSHA-256: {$result->checksum}\nChecksum: {$result->checksumPath}\nFiles: {$result->fileCount}\n",
        );
    }
}
