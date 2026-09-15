<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Sensitive;

use Forwext\Core\Auth\Mfa\Challenge\MfaChallengeGrant;
use Forwext\Core\Auth\Mfa\Challenge\MfaChallengePurpose;
use Forwext\Core\Auth\Mfa\Challenge\MfaChallengeStore;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Auth\Mfa\MfaMethod;
use Forwext\Core\Auth\Mfa\Passkey\DatabasePasskeyService;
use Forwext\Core\Auth\Mfa\Passkey\PasskeyCeremony;
use Forwext\Core\Auth\Mfa\Policy\DatabaseMfaPolicyResolver;
use Forwext\Core\Auth\Mfa\Recovery\RecoveryCodeService;
use Forwext\Core\Auth\Mfa\Totp\DatabaseTotpService;
use Forwext\Core\Auth\Session\AuthSessionIdentity;

final readonly class SensitiveActionMfaService
{
    public function __construct(
        private DatabaseMfaPolicyResolver $policies,
        private MfaChallengeStore $challenges,
        private DatabaseTotpService $totp,
        private RecoveryCodeService $recovery,
        private DatabasePasskeyService $passkeys,
    ) {
    }

    public function begin(
        AuthSessionIdentity $session,
        string $actionKey,
        string $identityFingerprint,
        string $ipFingerprint,
        string $deviceFingerprint,
    ): ?string {
        if (!$this->policies->resolve($session->userId)->sensitiveActionRequired) {
            return null;
        }
        return $this->challenges->issue(
            $session->userId,
            $session->deviceId,
            $session->credentialVersion,
            MfaChallengePurpose::SensitiveAction,
            $actionKey,
            false,
            $identityFingerprint,
            $ipFingerprint,
            $deviceFingerprint,
        );
    }

    public function verifyCode(
        AuthSessionIdentity $session,
        string $actionKey,
        string $challengeToken,
        MfaMethod $method,
        string $code,
    ): bool {
        $grant = $this->matchingGrant($session, $actionKey, $challengeToken);
        if ($grant === null || $method === MfaMethod::Passkey) {
            return false;
        }
        $verified = $method === MfaMethod::Totp
            ? $this->totp->verify($session->userId, $code)
            : $this->recovery->consume($session->userId, $code);

        return $verified && $this->challenges->consume($challengeToken) !== null;
    }

    public function beginPasskey(
        AuthSessionIdentity $session,
        string $actionKey,
        string $challengeToken,
    ): PasskeyCeremony {
        if ($this->matchingGrant($session, $actionKey, $challengeToken) === null) {
            throw new MfaException('Sensitive-action MFA challenge is invalid or expired.');
        }
        return $this->passkeys->beginAuthentication($session->userId);
    }

    public function verifyPasskey(
        AuthSessionIdentity $session,
        string $actionKey,
        string $challengeToken,
        string $ceremonyToken,
        string $responseJson,
    ): bool {
        if ($this->matchingGrant($session, $actionKey, $challengeToken) === null) {
            return false;
        }
        if (!$this->passkeys->completeAuthentication($session->userId, $ceremonyToken, $responseJson)) {
            return false;
        }
        return $this->challenges->consume($challengeToken) !== null;
    }

    private function matchingGrant(
        AuthSessionIdentity $session,
        string $actionKey,
        string $challengeToken,
    ): ?MfaChallengeGrant {
        $grant = $this->challenges->inspect($challengeToken);
        if (
            $grant === null
            || $grant->purpose !== MfaChallengePurpose::SensitiveAction
            || $grant->actionKey !== $actionKey
            || $grant->userId->value() !== $session->userId->value()
            || $grant->deviceId !== $session->deviceId
            || $grant->credentialVersion !== $session->credentialVersion
        ) {
            return null;
        }
        return $grant;
    }
}
