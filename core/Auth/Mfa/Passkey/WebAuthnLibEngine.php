<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Passkey;

use Cose\Algorithms;
use Forwext\Core\Auth\Mfa\MfaException;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final readonly class WebAuthnLibEngine implements WebAuthnEngine
{
    private SerializerInterface $serializer;
    private AuthenticatorAttestationResponseValidator $registrationValidator;
    private AuthenticatorAssertionResponseValidator $authenticationValidator;

    public function __construct(
        private string $rpName,
        private string $rpId,
        private string $host,
    ) {
        if (
            $rpName === ''
            || strlen($rpName) > 100
            || filter_var('https://' . $rpId, FILTER_VALIDATE_URL) === false
            || filter_var('https://' . $host, FILTER_VALIDATE_URL) === false
            || str_contains($rpId, '/')
            || str_contains($host, '/')
        ) {
            throw new MfaException('WebAuthn relying-party configuration is invalid.');
        }

        $attestationManager = AttestationStatementSupportManager::create();
        $attestationManager->add(NoneAttestationStatementSupport::create());
        $this->serializer = (new WebauthnSerializerFactory($attestationManager))->create();

        $factory = new CeremonyStepManagerFactory();
        $this->registrationValidator = AuthenticatorAttestationResponseValidator::create($factory->creationCeremony());
        $this->authenticationValidator = AuthenticatorAssertionResponseValidator::create($factory->requestCeremony());
    }

    public function registrationOptions(
        string $userHandle,
        string $username,
        string $displayName,
        array $excludedCredentialIds = [],
    ): string {
        if ($userHandle === '' || strlen($userHandle) > 64 || $username === '' || $displayName === '') {
            throw new MfaException('WebAuthn user identity is invalid.');
        }

        $exclude = [];
        foreach ($excludedCredentialIds as $credentialId) {
            if ($credentialId === '') {
                throw new MfaException('WebAuthn excluded credential id is invalid.');
            }
            $exclude[] = PublicKeyCredentialDescriptor::create('public-key', $credentialId);
        }

        $options = PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId),
            PublicKeyCredentialUserEntity::create($username, $userHandle, $displayName),
            random_bytes(32),
            [
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_RS256),
            ],
            AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            300000,
        );

        return $this->serializer->serialize($options, 'json');
    }

    public function verifyRegistration(string $responseJson, string $optionsJson): VerifiedPasskeyCredential
    {
        try {
            $credential = $this->serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
            if (!$credential instanceof PublicKeyCredential || !$credential->response instanceof AuthenticatorAttestationResponse || !$options instanceof PublicKeyCredentialCreationOptions) {
                throw new MfaException('WebAuthn registration response has an invalid type.');
            }
            $record = $this->registrationValidator->check($credential->response, $options, $this->host);
            return new VerifiedPasskeyCredential(
                $record->publicKeyCredentialId,
                $this->serializer->serialize($record, 'json'),
            );
        } catch (MfaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MfaException('WebAuthn registration verification failed.', previous: $exception);
        }
    }

    public function authenticationOptions(array $credentialRecordJsons): string
    {
        $allow = [];
        foreach ($credentialRecordJsons as $json) {
            $record = $this->serializer->deserialize($json, CredentialRecord::class, 'json');
            if (!$record instanceof CredentialRecord) {
                throw new MfaException('Stored WebAuthn credential record is invalid.');
            }
            $allow[] = $record->getPublicKeyCredentialDescriptor();
        }
        if ($allow === []) {
            throw new MfaException('No active passkey is available for authentication.');
        }

        $options = PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId,
            $allow,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            300000,
        );
        return $this->serializer->serialize($options, 'json');
    }

    public function credentialIdFromResponse(string $responseJson): string
    {
        try {
            $credential = $this->serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');
            if (!$credential instanceof PublicKeyCredential || $credential->rawId === '') {
                throw new MfaException('WebAuthn assertion response has an invalid credential id.');
            }
            return $credential->rawId;
        } catch (MfaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MfaException('WebAuthn assertion response could not be loaded.', previous: $exception);
        }
    }

    public function verifyAuthentication(
        string $responseJson,
        string $optionsJson,
        string $credentialRecordJson,
        string $expectedUserHandle,
    ): string {
        try {
            $credential = $this->serializer->deserialize($responseJson, PublicKeyCredential::class, 'json');
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
            $record = $this->serializer->deserialize($credentialRecordJson, CredentialRecord::class, 'json');
            if (
                !$credential instanceof PublicKeyCredential
                || !$credential->response instanceof AuthenticatorAssertionResponse
                || !$options instanceof PublicKeyCredentialRequestOptions
                || !$record instanceof CredentialRecord
            ) {
                throw new MfaException('WebAuthn authentication response has an invalid type.');
            }
            $updated = $this->authenticationValidator->check(
                $record,
                $credential->response,
                $options,
                $this->host,
                $expectedUserHandle,
            );
            return $this->serializer->serialize($updated, 'json');
        } catch (MfaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MfaException('WebAuthn authentication verification failed.', previous: $exception);
        }
    }
}
