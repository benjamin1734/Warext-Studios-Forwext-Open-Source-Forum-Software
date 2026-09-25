<?php

declare(strict_types=1);

namespace Forwext\Tools\Cli\Command;

use Forwext\Core\Addon\Security\AddonArtifactSignatureVerifier;
use Forwext\Core\Addon\Security\AddonSignaturePolicy;
use Forwext\Core\Addon\Security\AddonTrustedKeyring;
use Forwext\Tools\Cli\CliCommand;
use Forwext\Tools\Cli\CliResult;
use RuntimeException;

final class AddonVerifyCommand implements CliCommand
{
    public function name(): string
    {
        return 'addon:verify';
    }

    public function description(): string
    {
        return 'Verify an add-on artifact signature against an explicit trusted keyring.';
    }

    public function execute(array $arguments): CliResult
    {
        if (count($arguments) < 3 || count($arguments) > 4) {
            return CliResult::failure(
                "Usage: addon:verify artifact.zip signature.json trusted-keys.json [optional|signed|official]\n",
                2,
            );
        }

        $raw = file_get_contents($arguments[2]);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read trusted add-on keyring.');
        }
        $policy = isset($arguments[3])
            ? (AddonSignaturePolicy::tryFrom($arguments[3])
                ?? throw new RuntimeException('Unknown add-on signature policy.'))
            : AddonSignaturePolicy::Optional;

        $result = (new AddonArtifactSignatureVerifier())->verify(
            $arguments[0],
            $arguments[1],
            AddonTrustedKeyring::fromJson($raw),
            $policy,
        );

        return CliResult::success(
            'Verified: ' . $result->trust->value
            . "\nSHA-256: " . $result->artifactChecksum
            . ($result->keyId === null ? '' : "\nKey: " . $result->keyId)
            . "\n",
        );
    }
}
