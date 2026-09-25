<?php

declare(strict_types=1);

namespace Forwext\Tools\Addon;

use Forwext\Core\Addon\Security\AddonArtifactSignature;
use InvalidArgumentException;
use RuntimeException;

final class AddonArtifactSigner
{
    public function sign(
        string $artifactPath,
        string $keyId,
        string $privateKeyPath,
        ?string $outputPath = null,
    ): string {
        if (!is_file($artifactPath) || is_link($artifactPath)) {
            throw new InvalidArgumentException('Add-on artifact is unavailable or unsafe.');
        }
        if (!is_file($privateKeyPath) || is_link($privateKeyPath)) {
            throw new InvalidArgumentException('Add-on private key file is unavailable or unsafe.');
        }
        $checksum = hash_file('sha256', $artifactPath);
        if (!is_string($checksum)) {
            throw new RuntimeException('Unable to checksum add-on artifact.');
        }

        $pem = file_get_contents($privateKeyPath);
        if (!is_string($pem)) {
            throw new RuntimeException('Unable to read add-on private key.');
        }
        $passphrase = getenv('FORWEXT_SIGNING_KEY_PASSPHRASE');
        $privateKey = openssl_pkey_get_private($pem, is_string($passphrase) ? $passphrase : '');
        if ($privateKey === false) {
            throw new InvalidArgumentException('Add-on private key is invalid or its passphrase is unavailable.');
        }

        $unsigned = new AddonArtifactSignature($keyId, $checksum, base64_encode('unsigned'));
        $signatureBytes = '';
        if (!openssl_sign($unsigned->signingPayload(), $signatureBytes, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign add-on artifact.');
        }
        $document = new AddonArtifactSignature($keyId, $checksum, base64_encode($signatureBytes));

        $output = $outputPath === null || trim($outputPath) === ''
            ? $artifactPath . '.sig.json'
            : $outputPath;
        if (file_exists($output) || is_link($output)) {
            throw new InvalidArgumentException('Refusing to overwrite an existing add-on signature document.');
        }
        $directory = dirname($output);
        if (!is_dir($directory) || is_link($directory)) {
            throw new InvalidArgumentException('Add-on signature output directory is unavailable or unsafe.');
        }

        $json = $document->json();
        $temporary = $output . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('Unable to write add-on signature document.');
            }
            if (!chmod($temporary, 0644) || !rename($temporary, $output)) {
                throw new RuntimeException('Unable to publish add-on signature document.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $output;
    }
}
