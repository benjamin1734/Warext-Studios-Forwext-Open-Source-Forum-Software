<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli\Command;

use Forwext\Core\Addon\AddonId;
use Forwext\Tools\Addon\AddonCodeGenerator;
use Forwext\Tools\Cli\CliCommand;
use Forwext\Tools\Cli\CliResult;

final readonly class MakeClassCommand implements CliCommand
{
    public function __construct(
        private AddonCodeGenerator $generator,
        private string $kind,
    ) {
    }

    public function name(): string
    {
        return 'make:' . $this->kind;
    }

    public function description(): string
    {
        return 'Generate a namespaced add-on ' . $this->kind . ' class.';
    }

    public function execute(array $arguments): CliResult
    {
        if (count($arguments) !== 2) {
            return CliResult::failure('Usage: ' . $this->name() . " Vendor/AddOn ClassName\n", 2);
        }

        $path = $this->generator->generate(
            AddonId::fromString($arguments[0]),
            $this->kind,
            $arguments[1],
        );

        return CliResult::success("Created {$path}\n");
    }
}
