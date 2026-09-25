<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli\Command;

use Forwext\Core\Addon\AddonId;
use Forwext\Tools\Addon\AddonIdeTypeGenerator;
use Forwext\Tools\Cli\CliCommand;
use Forwext\Tools\Cli\CliResult;

final readonly class IdeTypesCommand implements CliCommand
{
    public function __construct(private AddonIdeTypeGenerator $generator)
    {
    }

    public function name(): string
    {
        return 'ide:types';
    }

    public function description(): string
    {
        return 'Generate IDE-only typed add-on API metadata.';
    }

    public function execute(array $arguments): CliResult
    {
        if (count($arguments) !== 1) {
            return CliResult::failure("Usage: ide:types Vendor/AddOn\n", 2);
        }
        return CliResult::success(
            'Generated ' . $this->generator->generate(AddonId::fromString($arguments[0])) . "\n",
        );
    }
}
