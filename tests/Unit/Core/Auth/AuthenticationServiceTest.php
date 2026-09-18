<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Auth;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthenticationFingerprint;
use Forwext\Core\Auth\AuthenticationRejectedException;
use Forwext\Core\Auth\Credential\CredentialRecord;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Auth\Device\DeviceRecord;
use Forwext\Core\Auth\Device\DeviceRepository;
use Forwext\Core\Auth\Login\AuthenticationRateLimiter;
use Forwext\Core\Auth\Login\AuthenticationService;
use Forwext\Core\Auth\Login\LoginHistoryRecorder;
use Forwext\Core\Auth\Login\LoginOutcome;
use Forwext\Core\Auth\Login\LoginRequest;
use Forwext\Core\Auth\Mfa\Login\MfaLoginGate;
use Forwext\Core\Auth\Mfa\Login\SecondFactorRequiredException;
use Forwext\Core\Auth\Mfa\MfaMethod;
use Forwext\Core\Auth\Password\PasswordHasher;
use Forwext\Core\Auth\Remember\RememberTokenService;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserAuthenticationAvailability;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Security\Secret\SecretStore;
use Forwext\Core\Session\SessionRecord;
use Forwext\Core\Session\SessionStore;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class AuthenticationServiceTest extends TestCase
{
    public function testSuccessfulLoginCreatesSessionAndKeepsCredentialVersionDuringRehash(): void
    {
        $clock = new LoginFrozenClock('2026-09-15 08:00:00');
        $user = self::user(UserStatus::Active, $clock->now());
        $credentials = new LoginMemoryCredentialStore(new CredentialRecord($user->id(), 'stored-hash', 7, $clock->now()));
        $sessions = new LoginMemorySessionStore($clock);
        $history = new LoginMemoryHistory();
        $service = self::service($user, $credentials, new LoginFakeHasher(true, true), $sessions, $history, new AllowingMfaGate(), $clock);

        $result = $service->login(new LoginRequest(
            'active_user',
            'correct-password',
            '203.0.113.20',
            'Forwext Test Browser/1.0',
        ));

        self::assertStringStartsWith('s_', $result->sessionId);
        self::assertSame(1, $sessions->writes);
        self::assertSame(1, $credentials->rehashCalls);
        self::assertSame(7, $credentials->find($user->id())?->version);
        self::assertSame([LoginOutcome::Success], $history->outcomes);
    }

    public function testUnknownIdentityUsesDummyVerifyAndGenericFailure(): void
    {
        $clock = new LoginFrozenClock('2026-09-15 08:00:00');
        $hasher = new LoginFakeHasher(false, false);
        $history = new LoginMemoryHistory();
        $service = self::service(null, new LoginMemoryCredentialStore(), $hasher, new LoginMemorySessionStore($clock), $history, new AllowingMfaGate(), $clock);

        try {
            $service->login(new LoginRequest('missing@example.com', 'wrong-password', '203.0.113.21', 'Forwext Test Browser/1.0'));
            self::fail('Expected authentication rejection.');
        } catch (AuthenticationRejectedException $exception) {
            self::assertSame('Authentication failed.', $exception->getMessage());
        }

        self::assertSame(1, $hasher->dummyCalls);
        self::assertSame([LoginOutcome::InvalidCredentials], $history->outcomes);
    }

    public function testDisciplineAvailabilityRejectsOtherwiseValidCredentialsBeforeSessionCreation(): void
    {
        $clock = new LoginFrozenClock('2026-09-15 08:00:00');
        $user = self::user(UserStatus::Active, $clock->now());
        $credentials = new LoginMemoryCredentialStore(new CredentialRecord($user->id(), 'stored-hash', 1, $clock->now()));
        $sessions = new LoginMemorySessionStore($clock);
        $history = new LoginMemoryHistory();
        $service = self::service(
            $user,
            $credentials,
            new LoginFakeHasher(true, false),
            $sessions,
            $history,
            new AllowingMfaGate(),
            $clock,
            new LoginAvailability(false),
        );

        $this->expectException(AuthenticationRejectedException::class);
        try {
            $service->login(new LoginRequest(
                'active_user',
                'correct-password',
                '203.0.113.23',
                'Forwext Test Browser/1.0',
            ));
        } finally {
            self::assertSame(0, $sessions->writes);
            self::assertSame([LoginOutcome::AccountUnavailable], $history->outcomes);
        }
    }

    public function testMfaRequiredLoginDoesNotCreateAuthenticatedSession(): void
    {
        $clock = new LoginFrozenClock('2026-09-15 08:00:00');
        $user = self::user(UserStatus::Active, $clock->now());
        $credentials = new LoginMemoryCredentialStore(new CredentialRecord($user->id(), 'stored-hash', 1, $clock->now()));
        $sessions = new LoginMemorySessionStore($clock);
        $history = new LoginMemoryHistory();
        $service = self::service($user, $credentials, new LoginFakeHasher(true, false), $sessions, $history, new RequiringMfaGate(), $clock);

        $this->expectException(SecondFactorRequiredException::class);
        try {
            $service->login(new LoginRequest('active_user', 'correct-password', '203.0.113.22', 'Forwext Test Browser/1.0'));
        } finally {
            self::assertSame(0, $sessions->writes);
            self::assertSame([LoginOutcome::MfaRequired], $history->outcomes);
        }
    }

    private static function service(
        ?User $user,
        LoginMemoryCredentialStore $credentials,
        LoginFakeHasher $hasher,
        LoginMemorySessionStore $sessions,
        LoginMemoryHistory $history,
        MfaLoginGate $mfaGate,
        Clock $clock,
        ?UserAuthenticationAvailability $availability = null,
    ): AuthenticationService {
        return new AuthenticationService(
            new LoginMemoryUserRepository($user),
            $credentials,
            $hasher,
            new AuthenticationFingerprint(new LoginSecretStore(['authentication.fingerprint_key' => str_repeat('f', 40)])),
            new LoginAllowingLimiter(),
            new LoginMemoryDeviceRepository(),
            new AuthSessionManager($sessions, $credentials, 7200, $clock),
            new RememberTokenService(new LoginDatabase(), $credentials),
            $history,
            $mfaGate,
            clock: $clock,
            availability: $availability,
        );
    }

    private static function user(UserStatus $status, DateTimeImmutable $now): User
    {
        return User::create(
            UserId::generate(),
            Username::fromString('active_user'),
            EmailAddress::fromString('active@example.com'),
            $status,
            UserLocale::fromString('en-US'),
            UserTimezone::fromString('UTC'),
            $now,
        );
    }
}

final class AllowingMfaGate implements MfaLoginGate
{
    public function enforce(EntityId $userId, string $deviceId, int $credentialVersion, bool $rememberRequested, ?string $trustedDeviceToken, string $identityFingerprint, string $ipFingerprint, string $deviceFingerprint): void
    {
    }
}

final class RequiringMfaGate implements MfaLoginGate
{
    public function enforce(EntityId $userId, string $deviceId, int $credentialVersion, bool $rememberRequested, ?string $trustedDeviceToken, string $identityFingerprint, string $ipFingerprint, string $deviceFingerprint): void
    {
        throw new SecondFactorRequiredException('mfa_' . str_repeat('a', 64), [MfaMethod::Totp], false);
    }
}

final class LoginMemoryUserRepository implements UserRepository
{
    public function __construct(private ?User $user = null)
    {
    }

    public function find(EntityId $id): ?User { return $this->user?->id()->value() === $id->value() ? $this->user : null; }
    public function findByUsername(Username $username): ?User { return $this->user?->username()->key() === $username->key() ? $this->user : null; }
    public function findByEmail(EmailAddress $email): ?User { return $this->user?->email()->key() === $email->key() ? $this->user : null; }
    public function save(User $user): void { $this->user = $user; }
    public function history(EntityId $id, int $limit = 100, int $offset = 0): array { return []; }
}

final class LoginMemoryCredentialStore implements CredentialStore
{
    public int $rehashCalls = 0;

    public function __construct(private ?CredentialRecord $record = null)
    {
    }

    public function find(EntityId $userId): ?CredentialRecord { return $this->record !== null && $this->record->userId->value() === $userId->value() ? $this->record : null; }
    public function create(EntityId $userId, string $passwordHash, DateTimeImmutable $changedAt): CredentialRecord { return $this->record = new CredentialRecord($userId, $passwordHash, 1, $changedAt); }
    public function rehash(EntityId $userId, int $expectedVersion, string $passwordHash): CredentialRecord { ++$this->rehashCalls; return $this->record = new CredentialRecord($userId, $passwordHash, $expectedVersion, $this->record?->passwordChangedAt ?? new DateTimeImmutable('@0')); }
    public function replacePassword(EntityId $userId, int $expectedVersion, string $passwordHash, DateTimeImmutable $changedAt): CredentialRecord { return $this->record = new CredentialRecord($userId, $passwordHash, $expectedVersion + 1, $changedAt); }
}

final class LoginFakeHasher implements PasswordHasher
{
    public int $dummyCalls = 0;
    public function __construct(private bool $verifyResult, private bool $rehashResult) {}
    public function hash(#[SensitiveParameter] string $password): string { return 'new-hash'; }
    public function verify(#[SensitiveParameter] string $password, string $hash): bool { return $this->verifyResult; }
    public function needsRehash(string $hash): bool { return $this->rehashResult; }
    public function dummyVerify(#[SensitiveParameter] string $password): void { ++$this->dummyCalls; }
}

final class LoginAllowingLimiter implements AuthenticationRateLimiter
{
    public function consume(string $fingerprint, int $limit, int $windowSeconds, DateTimeImmutable $now): bool { return true; }
}

final class LoginMemoryDeviceRepository implements DeviceRepository
{
    public function touch(EntityId $userId, ?string $presentedDeviceId, string $userAgentFingerprint, string $ipFingerprint, DateTimeImmutable $now): DeviceRecord
    {
        return new DeviceRecord($presentedDeviceId ?? str_repeat('d', 32), $userId, $userAgentFingerprint, $ipFingerprint, $now, $now);
    }
    public function revoke(EntityId $userId, string $deviceId, DateTimeImmutable $now): bool { return true; }
}

final class LoginMemoryHistory implements LoginHistoryRecorder
{
    /** @var list<LoginOutcome> */
    public array $outcomes = [];
    public function record(?EntityId $userId, string $identityFingerprint, string $ipFingerprint, string $deviceFingerprint, LoginOutcome $outcome, DateTimeImmutable $occurredAt): void { $this->outcomes[] = $outcome; }
}

final class LoginMemorySessionStore implements SessionStore
{
    /** @var array<string, SessionRecord> */
    private array $records = [];
    public int $writes = 0;
    public function __construct(private Clock $clock) {}
    public function read(string $sessionId): ?SessionRecord { return $this->records[$sessionId] ?? null; }
    public function write(string $sessionId, string $payload, int $ttlSeconds): void { ++$this->writes; $this->records[$sessionId] = new SessionRecord($payload, $this->clock->now()->modify('+' . $ttlSeconds . ' seconds')); }
    public function delete(string $sessionId): bool { $exists = isset($this->records[$sessionId]); unset($this->records[$sessionId]); return $exists; }
    public function collectGarbage(int $limit = 1000): int { return 0; }
}

final class LoginDatabase implements TransactionalQueryExecutor
{
    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { return $callback($this); }
}

final class LoginSecretStore implements SecretStore
{
    /** @param array<string,string> $values */
    public function __construct(private array $values) {}
    public function has(string $name): bool { return isset($this->values[$name]); }
    public function get(string $name): ?string { return $this->values[$name] ?? null; }
    public function set(string $name, string $value): void { $this->values[$name] = $value; }
    public function delete(string $name): bool { if (!isset($this->values[$name])) { return false; } unset($this->values[$name]); return true; }
    public function all(): array { return $this->values; }
}

final class LoginFrozenClock implements Clock
{
    private DateTimeImmutable $time;
    public function __construct(string $time) { $this->time = new DateTimeImmutable($time, new DateTimeZone('UTC')); }
    public function now(): DateTimeImmutable { return $this->time; }
}


final readonly class LoginAvailability implements UserAuthenticationAvailability
{
    public function __construct(private bool $allowed) {}
    public function allows(EntityId $userId): bool { return $this->allowed; }
}
