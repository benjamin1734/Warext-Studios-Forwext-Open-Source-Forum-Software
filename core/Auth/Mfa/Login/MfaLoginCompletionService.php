<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Login;

use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Auth\Login\LoginHistoryRecorder;
use Forwext\Core\Auth\Login\LoginOutcome;
use Forwext\Core\Auth\Login\LoginResult;
use Forwext\Core\Auth\Mfa\Challenge\MfaChallengeGrant;
use Forwext\Core\Auth\Mfa\Challenge\MfaChallengePurpose;
use Forwext\Core\Auth\Mfa\Challenge\MfaChallengeStore;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Auth\Mfa\MfaMethod;
use Forwext\Core\Auth\Mfa\Passkey\DatabasePasskeyService;
use Forwext\Core\Auth\Mfa\Passkey\PasskeyCeremony;
use Forwext\Core\Auth\Mfa\Recovery\RecoveryCodeService;
use Forwext\Core\Auth\Mfa\Totp\DatabaseTotpService;
use Forwext\Core\Auth\Mfa\TrustedDevice\TrustedDeviceService;
use Forwext\Core\Auth\Remember\RememberTokenService;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class MfaLoginCompletionService
{
    public function __construct(
        private MfaChallengeStore $challenges,
        private CredentialStore $credentials,
        private DatabaseTotpService $totp,
        private RecoveryCodeService $recovery,
        private DatabasePasskeyService $passkeys,
        private AuthSessionManager $sessions,
        private RememberTokenService $rememberTokens,
        private TrustedDeviceService $trustedDevices,
        private LoginHistoryRecorder $history,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function completeCode(string $challengeToken, MfaMethod $method, string $code, bool $trustDevice = false): MfaLoginCompletionResult
    {
        $grant = $this->requireLoginGrant($challengeToken);
        $verified = match ($method) {
            MfaMethod::Totp => $this->totp->verify($grant->userId, $code),
            MfaMethod::RecoveryCode => $this->recovery->consume($grant->userId, $code),
            MfaMethod::Passkey => false,
        };
        if (!$verified) {
            throw new MfaException('Multi-factor verification failed.');
        }
        return $this->finalize($challengeToken, $grant, $trustDevice);
    }

    public function beginPasskey(string $challengeToken): PasskeyCeremony
    {
        return $this->passkeys->beginAuthentication($this->requireLoginGrant($challengeToken)->userId);
    }

    public function completePasskey(string $challengeToken, string $ceremonyToken, string $responseJson, bool $trustDevice = false): MfaLoginCompletionResult
    {
        $grant = $this->requireLoginGrant($challengeToken);
        if (!$this->passkeys->completeAuthentication($grant->userId, $ceremonyToken, $responseJson)) {
            throw new MfaException('Passkey verification failed.');
        }
        return $this->finalize($challengeToken, $grant, $trustDevice);
    }

    private function finalize(string $challengeToken, MfaChallengeGrant $grant, bool $trustDevice): MfaLoginCompletionResult
    {
        $credential = $this->credentials->find($grant->userId);
        if ($credential === null || $credential->version !== $grant->credentialVersion) {
            throw new MfaException('MFA challenge credential version is stale.');
        }
        $consumed = $this->challenges->consume($challengeToken);
        if ($consumed === null || $consumed->userId->value() !== $grant->userId->value()) {
            throw new MfaException('MFA challenge was already consumed.');
        }
        $sessionId = $this->sessions->create($grant->userId, $grant->deviceId, $grant->credentialVersion);
        try {
            $remember = $grant->rememberRequested
                ? $this->rememberTokens->issue($grant->userId, $grant->deviceId, $grant->credentialVersion, $this->clock->now())
                : null;
            $trusted = $trustDevice
                ? $this->trustedDevices->issue($grant->userId, $grant->deviceId, $grant->credentialVersion)->token
                : null;
            $this->history->record(
                $grant->userId, $grant->identityFingerprint, $grant->ipFingerprint, $grant->deviceFingerprint,
                LoginOutcome::Success, $this->clock->now(),
            );
        } catch (\Throwable $exception) {
            $this->sessions->revoke($sessionId);
            throw $exception;
        }
        return new MfaLoginCompletionResult(
            new LoginResult($grant->userId, $sessionId, $grant->deviceId, $remember),
            $trusted,
        );
    }

    private function requireLoginGrant(string $token): MfaChallengeGrant
    {
        $grant = $this->challenges->inspect($token);
        if ($grant === null || $grant->purpose !== MfaChallengePurpose::Login) {
            throw new MfaException('MFA login challenge is invalid or expired.');
        }
        return $grant;
    }
}
