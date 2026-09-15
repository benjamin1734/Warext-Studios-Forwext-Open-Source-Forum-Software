<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Enrollment;

use Forwext\Core\Auth\Mfa\Challenge\MfaChallengePurpose;
use Forwext\Core\Auth\Mfa\Challenge\MfaChallengeStore;
use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Auth\Mfa\MfaFactorAvailability;
use Forwext\Core\Auth\Mfa\Passkey\DatabasePasskeyService;
use Forwext\Core\Auth\Mfa\Passkey\PasskeyCeremony;
use Forwext\Core\Auth\Mfa\Recovery\RecoveryCodeService;
use Forwext\Core\Auth\Mfa\Totp\DatabaseTotpService;
use Forwext\Core\Auth\Mfa\Totp\TotpEnrollment;

final readonly class PendingMfaEnrollmentService
{
    public function __construct(
        private MfaChallengeStore $challenges,
        private MfaFactorAvailability $availability,
        private DatabaseTotpService $totp,
        private RecoveryCodeService $recoveryCodes,
        private DatabasePasskeyService $passkeys,
    ) {
    }

    public function beginTotp(string $challengeToken, string $accountLabel): TotpEnrollment
    {
        $grant = $this->requireEnrollmentGrant($challengeToken);
        return $this->totp->beginEnrollment($grant->userId, $accountLabel);
    }

    /** @return list<string> */
    public function confirmTotp(string $challengeToken, string $code): array
    {
        $grant = $this->requireLoginGrant($challengeToken);
        if (!$this->totp->confirmEnrollment($grant->userId, $code)) {
            throw new MfaException('TOTP enrollment verification failed.');
        }
        return $this->recoveryCodes->regenerate($grant->userId);
    }

    public function beginPasskey(
        string $challengeToken,
        string $username,
        string $displayName,
    ): PasskeyCeremony {
        $grant = $this->requireEnrollmentGrant($challengeToken);
        return $this->passkeys->beginRegistration($grant->userId, $username, $displayName);
    }

    /** @return list<string> */
    public function completePasskey(
        string $challengeToken,
        string $ceremonyToken,
        string $responseJson,
        string $label,
    ): array {
        $grant = $this->requireLoginGrant($challengeToken);
        $this->passkeys->completeRegistration($grant->userId, $ceremonyToken, $responseJson, $label);
        return $this->recoveryCodes->regenerate($grant->userId);
    }

    private function requireEnrollmentGrant(string $challengeToken): \Forwext\Core\Auth\Mfa\Challenge\MfaChallengeGrant
    {
        $grant = $this->requireLoginGrant($challengeToken);
        if ($this->availability->methods($grant->userId) !== []) {
            throw new MfaException('MFA enrollment is not available for this challenge.');
        }
        return $grant;
    }

    private function requireLoginGrant(string $challengeToken): \Forwext\Core\Auth\Mfa\Challenge\MfaChallengeGrant
    {
        $grant = $this->challenges->inspect($challengeToken);
        if ($grant === null || $grant->purpose !== MfaChallengePurpose::Login) {
            throw new MfaException('MFA login challenge is invalid or expired.');
        }
        return $grant;
    }
}
