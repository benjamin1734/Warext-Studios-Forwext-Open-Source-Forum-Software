<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Login;

use Forwext\Core\Auth\AuthenticationFingerprint;
use Forwext\Core\Auth\AuthenticationRejectedException;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Auth\Device\DeviceRepository;
use Forwext\Core\Auth\Mfa\Login\MfaLoginGate;
use Forwext\Core\Auth\Mfa\Login\SecondFactorRequiredException;
use Forwext\Core\Auth\Password\PasswordHasher;
use Forwext\Core\Auth\Remember\RememberTokenService;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserAuthenticationAvailability;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use InvalidArgumentException;

final readonly class AuthenticationService
{
    public function __construct(
        private UserRepository $users,
        private CredentialStore $credentials,
        private PasswordHasher $hasher,
        private AuthenticationFingerprint $fingerprints,
        private AuthenticationRateLimiter $rateLimiter,
        private DeviceRepository $devices,
        private AuthSessionManager $sessions,
        private RememberTokenService $rememberTokens,
        private LoginHistoryRecorder $history,
        private MfaLoginGate $mfaGate,
        private int $identityAttemptLimit = 10,
        private int $networkAttemptLimit = 50,
        private int $attemptWindowSeconds = 900,
        private Clock $clock = new SystemClock(),
        private ?UserAuthenticationAvailability $availability = null,
    ) {
    }

    public function login(LoginRequest $request): LoginResult
    {
        $now = $this->clock->now();
        $identityFingerprint = $this->fingerprints->identity($request->identifier);
        $ipFingerprint = $this->fingerprints->ip($request->clientIp);
        $deviceFingerprint = $this->fingerprints->userAgent($request->userAgent);
        $attemptGuard = new AuthenticationAttemptGuard($this->rateLimiter, $this->identityAttemptLimit, $this->networkAttemptLimit, $this->attemptWindowSeconds);
        if (!$attemptGuard->allows($identityFingerprint, $ipFingerprint, $now)) {
            $this->history->record(null, $identityFingerprint, $ipFingerprint, $deviceFingerprint, LoginOutcome::RateLimited, $now);
            throw new AuthenticationRejectedException();
        }

        $user = $this->findUser($request->identifier);
        $credential = $user !== null ? $this->credentials->find($user->id()) : null;
        if ($credential === null) {
            $this->hasher->dummyVerify($request->password);
            $this->reject($user, $identityFingerprint, $ipFingerprint, $deviceFingerprint, LoginOutcome::InvalidCredentials, $now);
        }
        if (!$this->hasher->verify($request->password, $credential->passwordHash)) {
            $this->reject($user, $identityFingerprint, $ipFingerprint, $deviceFingerprint, LoginOutcome::InvalidCredentials, $now);
        }
        if ($user === null || !$user->status()->canAuthenticateNormally()
            || ($this->availability !== null && !$this->availability->allows($user->id()))
        ) {
            $this->reject($user, $identityFingerprint, $ipFingerprint, $deviceFingerprint, LoginOutcome::AccountUnavailable, $now);
        }
        if ($this->hasher->needsRehash($credential->passwordHash)) {
            $credential = $this->credentials->rehash($user->id(), $credential->version, $this->hasher->hash($request->password));
        }

        $device = $this->devices->touch($user->id(), $request->deviceId, $deviceFingerprint, $ipFingerprint, $now);
        if ($request->previousSessionId !== null) {
            $this->sessions->revoke($request->previousSessionId);
        }
        try {
            $this->mfaGate->enforce(
                $user->id(), $device->deviceId, $credential->version, $request->rememberMe, $request->trustedDeviceToken,
                $identityFingerprint, $ipFingerprint, $deviceFingerprint,
            );
        } catch (SecondFactorRequiredException $exception) {
            $this->history->record($user->id(), $identityFingerprint, $ipFingerprint, $deviceFingerprint, LoginOutcome::MfaRequired, $now);
            throw $exception;
        }

        $sessionId = $this->sessions->create($user->id(), $device->deviceId, $credential->version);
        try {
            $rememberToken = $request->rememberMe
                ? $this->rememberTokens->issue($user->id(), $device->deviceId, $credential->version, $now)
                : null;
            $this->history->record($user->id(), $identityFingerprint, $ipFingerprint, $deviceFingerprint, LoginOutcome::Success, $now);
        } catch (\Throwable $exception) {
            $this->sessions->revoke($sessionId);
            throw $exception;
        }
        return new LoginResult($user->id(), $sessionId, $device->deviceId, $rememberToken);
    }

    private function findUser(string $identifier): ?User
    {
        try {
            return str_contains($identifier, '@')
                ? $this->users->findByEmail(EmailAddress::fromString($identifier))
                : $this->users->findByUsername(Username::fromString($identifier));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function reject(?User $user, string $identityFingerprint, string $ipFingerprint, string $deviceFingerprint, LoginOutcome $outcome, \DateTimeImmutable $now): never
    {
        $this->history->record($user?->id(), $identityFingerprint, $ipFingerprint, $deviceFingerprint, $outcome, $now);
        throw new AuthenticationRejectedException();
    }
}
