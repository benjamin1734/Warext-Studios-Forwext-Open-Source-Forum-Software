<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use Forwext\Core\Auth\Credential\CredentialProvisioner;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use Forwext\Core\Registration\Captcha\CaptchaVerifier;

final readonly class RegistrationService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private UserRepository $users,
        private RegistrationPolicy $policy,
        private CaptchaVerifier $captcha,
        private DisposableEmailChecker $disposableEmails,
        private RegistrationRateLimiter $rateLimiter,
        private RegistrationFingerprint $fingerprint,
        private RegistrationInviteStore $invites,
        private LegalAcceptanceStore $legalAcceptances,
        private EmailVerificationTokenStore $verificationTokens,
        private CredentialProvisioner $credentials,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function register(RegistrationRequest $request): RegistrationResult
    {
        if ($this->policy->mode === RegistrationMode::Closed) {
            throw new RegistrationException('Registration is currently closed.');
        }
        if ($request->password === null || $request->password === '') {
            throw new RegistrationException('Password credential is required for password registration.');
        }

        $username = Username::fromString($request->username);
        $email = EmailAddress::fromString($request->email);
        $locale = UserLocale::fromString($request->locale);
        $timezone = UserTimezone::fromString($request->timezone);
        $now = $this->clock->now();
        $ipFingerprint = $this->fingerprint->ip($request->clientIp);
        $emailFingerprint = $this->fingerprint->email($email);

        if (!$this->rateLimiter->consume(
            'registration.ip',
            $ipFingerprint,
            $this->policy->ipAttemptLimit,
            $this->policy->rateLimitWindowSeconds,
            $now,
        ) || !$this->rateLimiter->consume(
            'registration.email',
            $emailFingerprint,
            $this->policy->emailAttemptLimit,
            $this->policy->rateLimitWindowSeconds,
            $now,
        )) {
            throw new RegistrationException('Registration rate limit exceeded.');
        }

        if ($this->policy->captchaRequired) {
            $token = $request->captchaToken ?? '';
            $verification = $this->captcha->verify($token, $request->clientIp);
            if (!$verification->success) {
                throw new RegistrationException('Registration challenge validation failed.');
            }
        }

        if ($this->disposableEmails->isDisposable($email)) {
            throw new RegistrationException('Registration email provider is not accepted.');
        }
        $this->assertLegalAcceptance($request);

        return $this->database->transaction(function () use (
            $request,
            $username,
            $email,
            $locale,
            $timezone,
            $now,
            $ipFingerprint,
        ): RegistrationResult {
            if ($this->users->findByUsername($username) !== null || $this->users->findByEmail($email) !== null) {
                throw new RegistrationException('Registration identity is unavailable.');
            }
            if ($this->policy->mode->requiresInvite()) {
                $inviteCode = $request->inviteCode ?? '';
                if (!$this->invites->consume($inviteCode, $now)) {
                    throw new RegistrationException('Registration invitation is invalid or unavailable.');
                }
            }

            $postVerificationStatus = $this->policy->mode->requiresApproval()
                ? UserStatus::PendingApproval
                : UserStatus::Active;
            $initialStatus = $this->policy->emailVerificationRequired
                ? UserStatus::PendingEmailVerification
                : $postVerificationStatus;

            $user = User::create(
                UserId::generate(),
                $username,
                $email,
                $initialStatus,
                $locale,
                $timezone,
                $now,
            );
            $this->users->save($user);
            $this->credentials->provision($user->id(), $request->password, $now);

            foreach ($this->policy->legalDocuments() as $document) {
                $this->legalAcceptances->record($user->id(), $document, $now, $ipFingerprint);
            }

            $verificationToken = null;
            if ($this->policy->emailVerificationRequired) {
                $verificationToken = $this->verificationTokens->issue(
                    $user->id(),
                    $postVerificationStatus,
                    $now,
                    $this->policy->emailVerificationTtlSeconds,
                );
            }

            return new RegistrationResult($user->id(), $user->status(), $verificationToken);
        });
    }

    private function assertLegalAcceptance(RegistrationRequest $request): void
    {
        foreach ($this->policy->legalDocuments() as $type => $requirement) {
            $acceptedVersion = $request->acceptedLegalVersions[$type] ?? null;
            if (!is_string($acceptedVersion) || !hash_equals($requirement->version, $acceptedVersion)) {
                throw new RegistrationException('Required legal documents have not been accepted.');
            }
        }
    }
}
