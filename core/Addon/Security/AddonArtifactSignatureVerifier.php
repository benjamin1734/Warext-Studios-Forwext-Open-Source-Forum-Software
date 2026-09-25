<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Security;

use InvalidArgumentException;
use RuntimeException;

final class AddonArtifactSignatureVerifier
{
    public function verify(
        string $artifactPath,
        ?string $signaturePath,
        AddonTrustedKeyring $keyring,
        AddonSignaturePolicy $policy = AddonSignaturePolicy::Optional,
    ): AddonSignatureVerificationResult {
        if (!is_file($artifactPath) || is_link($artifactPath)) {
            throw new InvalidArgumentException('Add-on artifact is unavailable or unsafe.');
        }
        $checksum = hash_file('sha256', $artifactPath);
        if (!is_string($checksum)) {
            throw new RuntimeException('Unable to checksum add-on artifact.');
        }

        if ($signaturePath === null || $signaturePath === '') {
            if ($policy !== AddonSignaturePolicy::Optional) {
                throw new InvalidArgumentException('Add-on signature is required by policy.');
            }

            return new AddonSignatureVerificationResult(AddonSignatureTrust::Unsigned, $checksum, null);
        }
        if (!is_file($signaturePath) || is_link($signaturePath)) {
            throw new InvalidArgumentException('Explicit add-on signature document is unavailable or unsafe.');
        }

        $raw = file_get_contents($signaturePath);
        if (!is_string($raw)) {
            throw new RuntimeException('Unable to read add-on signature document.');
        }
        $signature = AddonArtifactSignature::fromJson($raw);
        if (!hash_equals($signature->artifactChecksum, $checksum)) {
            throw new InvalidArgumentException('Add-on signature checksum does not match the artifact.');
        }

        $trusted = $keyring->find($signature->keyId);
        if (!$trusted instanceof AddonTrustedKey) {
            throw new InvalidArgumentException('Add-on signature key is not trusted.');
        }
        $publicKey = openssl_pkey_get_public($trusted->publicKeyPem);
        if ($publicKey === false) {
            throw new InvalidArgumentException('Trusted add-on public key is unavailable.');
        }
        $decoded = base64_decode($signature->signature, true);
        if (!is_string($decoded)) {
            throw new InvalidArgumentException('Add-on signature encoding is invalid.');
        }
        $verified = openssl_verify(
            $signature->signingPayload(),
            $decoded,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            throw new InvalidArgumentException('Add-on artifact signature verification failed.');
        }

        $trust = $trusted->official ? AddonSignatureTrust::Official : AddonSignatureTrust::Signed;
        if ($policy === AddonSignaturePolicy::RequireOfficial && $trust !== AddonSignatureTrust::Official) {
            throw new InvalidArgumentException('Add-on signature is valid but is not an official trusted signature.');
        }

        return new AddonSignatureVerificationResult($trust, $checksum, $trusted->id);
    }
}
