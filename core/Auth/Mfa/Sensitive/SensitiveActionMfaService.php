<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Sensitive;

use Forwext\Core\Auth\Mfa\Challenge\MfaChallengePurpose;
use Forwext\Core\Auth\Mfa\Challenge\MfaChallengeStore;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Auth\Mfa\MfaMethod;
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
    ) {
    }

    public function begin(AuthSessionIdentity $session, string $actionKey, string $identityFingerprint, string $ipFingerprint, string $deviceFingerprint): ?string
    {
        if (!$this->policies->resolve($session->userId)->sensitiveActionRequired) {
            return null;
        }
        return $this->challenges->issue(
            $session->userId, $session->deviceId, $session->credentialVersion,
            MfaChallengePurpose::SensitiveAction, $actionKey, false,
            $identityFingerprint, $ipFingerprint, $deviceFingerprint,
        );
    }

    public function verifyCode(AuthSessionIdentity $session, string $actionKey, string $challengeToken, MfaMethod $method, string $code): bool
    {
        $grant = $this->challenges->inspect($challengeToken);
        if (
            $grant === null
            || $grant->purpose !== MfaChallengePurpose::SensitiveAction
            || $grant->actionKey !== $actionKey
            || $grant->userId->value() !== $session->userId->value()
            || $grant->deviceId !== $session->deviceId
            || $grant->credentialVersion !== $session->credentialVersion
        ) {
            return false;
        }
        $verified = match ($method) {
            MfaMethod::Totp => $this->totp->verify($session->userId, $code),
            MfaMethod::RecoveryCode => $this->recovery->consume($session->userId, $code),
            MfaMethod::Passkey => throw new MfaException('Passkey sensitive-action verification uses the WebAuthn ceremony flow.'),
        };
        return $verified && $this->challenges->consume($challengeToken) !== null;
    }
}
