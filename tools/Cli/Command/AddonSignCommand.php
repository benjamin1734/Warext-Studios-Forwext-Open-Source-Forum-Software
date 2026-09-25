<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli\Command;

use Forwext\Tools\Addon\AddonArtifactSigner;
use Forwext\Tools\Cli\CliCommand;
use Forwext\Tools\Cli\CliResult;

final readonly class AddonSignCommand implements CliCommand
{
    public function __construct(private AddonArtifactSigner $signer)
    {
    }

    public function name(): string
    {
        return 'addon:sign';
    }

    public function description(): string
    {
        return 'Sign a built add-on artifact with an OpenSSL private key.';
    }

    public function execute(array $arguments): CliResult
    {
        if (count($arguments) < 3 || count($arguments) > 4) {
            return CliResult::failure(
                "Usage: addon:sign artifact.zip key-id private-key.pem [signature.json]\n",
                2,
            );
        }

        $path = $this->signer->sign($arguments[0], $arguments[1], $arguments[2], $arguments[3] ?? null);

        return CliResult::success("Signature: {$path}\n");
    }
}
