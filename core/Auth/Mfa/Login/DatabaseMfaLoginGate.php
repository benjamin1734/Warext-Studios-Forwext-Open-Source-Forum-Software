<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Login;

use Forwext\Core\Auth\Mfa\Challenge\MfaChallengePurpose;
use Forwext\Core\Auth\Mfa\Challenge\MfaChallengeStore;
use Forwext\Core\Auth\Mfa\MfaFactorAvailability;
use Forwext\Core\Auth\Mfa\Policy\DatabaseMfaPolicyResolver;
use Forwext\Core\Auth\Mfa\TrustedDevice\TrustedDeviceService;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class DatabaseMfaLoginGate implements MfaLoginGate
{
    public function __construct(
        private DatabaseMfaPolicyResolver $policies,
        private MfaFactorAvailability $availability,
        private TrustedDeviceService $trustedDevices,
        private MfaChallengeStore $challenges,
    ) {
    }

    public function enforce(
        EntityId $userId,
        string $deviceId,
        int $credentialVersion,
        bool $rememberRequested,
        ?string $trustedDeviceToken,
        string $identityFingerprint,
        string $ipFingerprint,
        string $deviceFingerprint,
    ): void {
        $policy = $this->policies->resolve($userId);
        if (!$policy->loginRequired) {
            return;
        }
        if (
            $policy->trustedDeviceMayBypassLogin
            && $trustedDeviceToken !== null
            && $this->trustedDevices->validate($userId, $deviceId, $credentialVersion, $trustedDeviceToken)
        ) {
            return;
        }

        $methods = $this->availability->methods($userId);
        $challenge = $this->challenges->issue(
            $userId,
            $deviceId,
            $credentialVersion,
            MfaChallengePurpose::Login,
            rememberRequested: $rememberRequested,
            identityFingerprint: $identityFingerprint,
            ipFingerprint: $ipFingerprint,
            deviceFingerprint: $deviceFingerprint,
        );
        throw new SecondFactorRequiredException($challenge, $methods, $methods === []);
    }
}
