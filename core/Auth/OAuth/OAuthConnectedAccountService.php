<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use DateInterval;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Security\Secret\SecretStore;

final readonly class OAuthConnectedAccountService
{
    public function __construct(
        private OAuthProviderRegistry $providers,
        private OAuthHttpClient $http,
        private OAuthTransactionStore $transactions,
        private ConnectedAccountStore $accounts,
        private UserRepository $users,
        private CredentialStore $credentials,
        private SecretStore $secrets,
        private Clock $clock,
        private int $transactionTtlSeconds = 600,
    ) {
        if ($transactionTtlSeconds < 60 || $transactionTtlSeconds > 1800) {
            throw new OAuthException('OAuth transaction TTL must be between 60 and 1800 seconds.');
        }
    }

    public function begin(string $providerId, string $redirectUri, ?EntityId $intendedUserId = null): OAuthAuthorizationStart
    {
        [$provider, $config] = $this->providers->resolve($providerId);
        $config->assertUsable($redirectUri);
        if ($intendedUserId !== null) {
            $this->requireUser($intendedUserId);
        }
        $state = Pkce::state();
        $verifier = Pkce::verifier();
        $now = $this->clock->now();
        $expires = $now->add(new DateInterval('PT' . $this->transactionTtlSeconds . 'S'));
        $this->transactions->create(new OAuthTransaction(
            Pkce::stateHash($state),
            $providerId,
            $verifier,
            $redirectUri,
            $intendedUserId,
            $now,
            $expires,
        ));
        return new OAuthAuthorizationStart(
            $provider->authorizationUrl($config, $redirectUri, $state, Pkce::challenge($verifier)),
            $state,
        );
    }

    public function complete(
        string $providerId,
        string $state,
        string $code,
        string $redirectUri,
        ?EntityId $currentUserId = null,
    ): User {
        if ($code === '' || strlen($code) > 8192) {
            throw new OAuthException('OAuth authorization code is invalid.');
        }
        [$provider, $config] = $this->providers->resolve($providerId);
        $config->assertUsable($redirectUri);
        $transaction = $this->transactions->consume(
            Pkce::stateHash($state),
            $providerId,
            $redirectUri,
            $this->clock->now(),
        );
        if (!$transaction instanceof OAuthTransaction) {
            throw new OAuthException('OAuth transaction is invalid, expired or already consumed.');
        }
        $intended = $transaction->intendedUserId;
        if ($intended !== null && ($currentUserId === null || !$intended->equals($currentUserId))) {
            throw new OAuthException('OAuth account-link transaction does not belong to the current user.');
        }
        if ($intended === null && $currentUserId !== null) {
            throw new OAuthException('OAuth login transaction cannot be converted into an account-link transaction.');
        }
        $clientSecret = $this->secrets->get($config->clientSecretName);
        if (!is_string($clientSecret) || $clientSecret === '') {
            throw new OAuthException('OAuth client secret is not configured.');
        }
        $identity = $provider->resolveIdentity(
            $config,
            $clientSecret,
            $redirectUri,
            $code,
            $transaction->codeVerifier,
            $this->http,
        );
        if ($identity->providerId !== $providerId) {
            throw new OAuthException('OAuth provider returned an identity for a different provider.');
        }
        $existing = $this->accounts->find($providerId, $identity->subject);
        if ($existing !== null) {
            if ($intended !== null && !$existing->userId->equals($intended)) {
                throw new OAuthException('This provider identity is already connected to another account.');
            }
            $user = $this->requireUser($existing->userId);
            $this->assertLoginAllowedWhenNeeded($user, $intended);
            $this->accounts->touch($providerId, $identity->subject, $this->clock->now());
            return $user;
        }

        $user = $intended !== null ? $this->requireUser($intended) : $this->resolveByVerifiedEmail($identity);
        $this->assertLoginAllowedWhenNeeded($user, $intended);
        $existingForUser = $this->accounts->findForUser($user->id(), $providerId);
        if ($existingForUser !== null) {
            throw new OAuthException('This local account already has a different identity connected for this provider.');
        }
        $now = $this->clock->now();
        $normalizedEmail = $identity->email !== null && $identity->emailVerified
            ? EmailAddress::fromString($identity->email)->key()
            : null;
        $this->accounts->link(new ConnectedAccount(
            $user->id(),
            $providerId,
            $identity->subject,
            $normalizedEmail,
            $identity->displayName,
            $now,
            $now,
        ));
        return $user;
    }

    public function unlink(EntityId $userId, string $providerId): bool
    {
        $this->requireUser($userId);
        $account = $this->accounts->findForUser($userId, $providerId);
        if ($account === null) {
            return false;
        }
        $hasPassword = $this->credentials->find($userId) !== null;
        if (!$hasPassword && $this->accounts->countForUser($userId) <= 1) {
            throw new OAuthException(
                'The last sign-in method cannot be disconnected until a password or another provider is configured.',
            );
        }
        return $this->accounts->unlink($userId, $providerId);
    }

    private function resolveByVerifiedEmail(OAuthIdentity $identity): User
    {
        if (!$identity->emailVerified || $identity->email === null) {
            throw new OAuthException('A verified provider email is required to link an unrecognized provider identity.');
        }
        $email = EmailAddress::fromString($identity->email);
        $user = $this->users->findByEmail($email);
        if (!$user instanceof User) {
            throw new OAuthException('No local account matches the verified provider email.');
        }
        return $user;
    }

    private function requireUser(EntityId $userId): User
    {
        $user = $this->users->find($userId);
        if (!$user instanceof User) {
            throw new OAuthException('OAuth operation references an unknown local account.');
        }
        return $user;
    }

    private function assertLoginAllowedWhenNeeded(User $user, ?EntityId $intendedUserId): void
    {
        if ($intendedUserId === null && !$user->status()->canAuthenticateNormally()) {
            throw new OAuthException('The local account is not permitted to authenticate.');
        }
    }
}
