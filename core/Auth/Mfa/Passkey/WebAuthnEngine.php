<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Passkey;

interface WebAuthnEngine
{
    /** @param list<string> $excludedCredentialIds Binary credential IDs. */
    public function registrationOptions(
        string $userHandle,
        string $username,
        string $displayName,
        array $excludedCredentialIds = [],
    ): string;

    public function verifyRegistration(string $responseJson, string $optionsJson): VerifiedPasskeyCredential;

    /** @param list<string> $credentialRecordJsons */
    public function authenticationOptions(array $credentialRecordJsons): string;

    public function credentialIdFromResponse(string $responseJson): string;

    public function verifyAuthentication(
        string $responseJson,
        string $optionsJson,
        string $credentialRecordJson,
        string $expectedUserHandle,
    ): string;
}
