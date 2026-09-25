<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli\Command;

use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\AddonVersion;
use Forwext\Tools\Addon\AddonScaffolder;
use Forwext\Tools\Cli\CliCommand;
use Forwext\Tools\Cli\CliResult;

final readonly class AddonCreateCommand implements CliCommand
{
    public function __construct(private AddonScaffolder $scaffolder)
    {
    }

    public function name(): string
    {
        return 'addon:create';
    }

    public function description(): string
    {
        return 'Create a validated Vendor/AddOn development scaffold.';
    }

    public function execute(array $arguments): CliResult
    {
        if (count($arguments) < 1 || count($arguments) > 2) {
            return CliResult::failure("Usage: addon:create Vendor/AddOn [version]\n", 2);
        }
        $id = AddonId::fromString($arguments[0]);
        $version = AddonVersion::parse($arguments[1] ?? '0.1.0');
        $files = $this->scaffolder->create($id, $version);

        return CliResult::success(
            "Created " . $id->value() . " " . $version->value() . "\n"
            . implode("\n", array_map(static fn (string $file): string => '  + ' . $file, $files))
            . "\n",
        );
    }
}
