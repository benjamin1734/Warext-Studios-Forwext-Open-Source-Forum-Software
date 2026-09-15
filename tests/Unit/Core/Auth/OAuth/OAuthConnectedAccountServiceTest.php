<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Auth\OAuth;

use DateTimeImmutable;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Auth\OAuth\ConnectedAccount;
use Forwext\Core\Auth\OAuth\ConnectedAccountStore;
use Forwext\Core\Auth\OAuth\OAuthConnectedAccountService;
use Forwext\Core\Auth\OAuth\OAuthException;
use Forwext\Core\Auth\OAuth\OAuthHttpClient;
use Forwext\Core\Auth\OAuth\OAuthIdentity;
use Forwext\Core\Auth\OAuth\OAuthProvider;
use Forwext\Core\Auth\OAuth\OAuthProviderConfig;
use Forwext\Core\Auth\OAuth\OAuthProviderRegistry;
use Forwext\Core\Auth\OAuth\OAuthTransaction;
use Forwext\Core\Auth\OAuth\OAuthTransactionStore;
use Forwext\Core\Auth\OAuth\Pkce;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Security\Secret\SecretStore;
use PHPUnit\Framework\TestCase;

final class OAuthConnectedAccountServiceTest extends TestCase
{
    public function testVerifiedProviderEmailLinksExistingAccount(): void
    {
        $now = new DateTimeImmutable('2026-09-15T12:45:00+00:00');
        $user = self::user('member@example.com', UserStatus::Active, $now);
        $state = str_repeat('s', 32);
        $transactions = $this->createMock(OAuthTransactionStore::class);
        $transactions->method('consume')->willReturn(self::transaction($state, $now));
        $accounts = $this->createMock(ConnectedAccountStore::class);
        $accounts->method('find')->willReturn(null);
        $accounts->method('findForUser')->willReturn(null);
        $accounts->expects(self::once())->method('link')->with(self::callback(
            static fn (ConnectedAccount $account): bool => $account->userId->equals($user->id())
                && $account->emailNormalized === 'member@example.com',
        ));
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::once())->method('findByEmail')->with(self::callback(
            static fn (EmailAddress $email): bool => $email->key() === 'member@example.com',
        ))->willReturn($user);

        $service = $this->service(
            new OAuthIdentity('google', 'subject-1', 'Member@Example.com', true, 'Member'),
            $transactions,
            $accounts,
            $users,
            $now,
        );
        self::assertSame($user, $service->complete(
            'google', $state, 'code', 'https://forum.example.test/oauth/google/callback',
        ));
    }

    public function testUnverifiedEmailCannotImplicitlyLink(): void
    {
        $now = new DateTimeImmutable('2026-09-15T12:45:00+00:00');
        $transactions = $this->createMock(OAuthTransactionStore::class);
        $transactions->method('consume')->willReturn(self::transaction(str_repeat('s', 32), $now));
        $accounts = $this->createMock(ConnectedAccountStore::class);
        $accounts->method('find')->willReturn(null);
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('findByEmail');
        $service = $this->service(
            new OAuthIdentity('google', 'subject-2', 'member@example.com', false),
            $transactions,
            $accounts,
            $users,
            $now,
        );
        $this->expectException(OAuthException::class);
        $service->complete('google', str_repeat('s', 32), 'code', 'https://forum.example.test/oauth/google/callback');
    }

    public function testLastAuthenticationMethodCannotBeUnlinked(): void
    {
        $now = new DateTimeImmutable('2026-09-15T12:45:00+00:00');
        $user = self::user('member@example.com', UserStatus::Active, $now);
        $accounts = $this->createMock(ConnectedAccountStore::class);
        $accounts->method('findForUser')->willReturn(new ConnectedAccount(
            $user->id(), 'google', 'subject-3', 'member@example.com', null, $now, $now,
        ));
        $accounts->method('countForUser')->willReturn(1);
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);
        $credentials = $this->createMock(CredentialStore::class);
        $credentials->method('find')->willReturn(null);
        $service = $this->service(
            new OAuthIdentity('google', 'subject-3', 'member@example.com', true),
            $this->createMock(OAuthTransactionStore::class),
            $accounts,
            $users,
            $now,
            $credentials,
        );
        $this->expectException(OAuthException::class);
        $service->unlink($user->id(), 'google');
    }

    public function testBannedAccountCannotAuthenticateThroughConnectedProvider(): void
    {
        $now = new DateTimeImmutable('2026-09-15T12:45:00+00:00');
        $user = self::user('banned@example.com', UserStatus::Banned, $now);
        $transactions = $this->createMock(OAuthTransactionStore::class);
        $transactions->method('consume')->willReturn(self::transaction(str_repeat('s', 32), $now));
        $accounts = $this->createMock(ConnectedAccountStore::class);
        $accounts->method('find')->willReturn(new ConnectedAccount(
            $user->id(), 'google', 'subject-4', 'banned@example.com', null, $now, $now,
        ));
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);
        $service = $this->service(
            new OAuthIdentity('google', 'subject-4', 'banned@example.com', true),
            $transactions,
            $accounts,
            $users,
            $now,
        );
        $this->expectException(OAuthException::class);
        $service->complete('google', str_repeat('s', 32), 'code', 'https://forum.example.test/oauth/google/callback');
    }

    private function service(
        OAuthIdentity $identity,
        OAuthTransactionStore $transactions,
        ConnectedAccountStore $accounts,
        UserRepository $users,
        DateTimeImmutable $now,
        ?CredentialStore $credentials = null,
    ): OAuthConnectedAccountService {
        $registry = new OAuthProviderRegistry(
            [new FixedIdentityProvider($identity)],
            [new OAuthProviderConfig(
                'google', true, 'client-id', 'oauth.google.client_secret',
                ['https://forum.example.test/oauth/google/callback'],
            )],
        );
        $secrets = $this->createMock(SecretStore::class);
        $secrets->method('get')->willReturn('client-secret');
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn($now);
        return new OAuthConnectedAccountService(
            $registry,
            $this->createMock(OAuthHttpClient::class),
            $transactions,
            $accounts,
            $users,
            $credentials ?? $this->createMock(CredentialStore::class),
            $secrets,
            $clock,
        );
    }

    private static function transaction(string $state, DateTimeImmutable $now): OAuthTransaction
    {
        return new OAuthTransaction(
            Pkce::stateHash($state), 'google', str_repeat('V', 43),
            'https://forum.example.test/oauth/google/callback', null, $now, $now->modify('+10 minutes'),
        );
    }

    private static function user(string $email, UserStatus $status, DateTimeImmutable $now): User
    {
        return User::hydrate(
            UserId::generate(),
            Username::fromString('member' . substr(hash('sha256', $email), 0, 8)),
            EmailAddress::fromString($email),
            $status,
            UserLocale::fromString('tr-TR'),
            UserTimezone::fromString('UTC'),
            [],
            $now,
            $now,
            1,
        );
    }
}

final readonly class FixedIdentityProvider implements OAuthProvider
{
    public function __construct(private OAuthIdentity $identity) {}
    public function id(): string { return 'google'; }
    public function authorizationUrl(OAuthProviderConfig $config, string $redirectUri, string $state, string $codeChallenge): string
    {
        return 'https://provider.example/authorize';
    }
    public function resolveIdentity(OAuthProviderConfig $config, string $clientSecret, string $redirectUri, string $code, string $codeVerifier, OAuthHttpClient $http): OAuthIdentity
    {
        return $this->identity;
    }
}
