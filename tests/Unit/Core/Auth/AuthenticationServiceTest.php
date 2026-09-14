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
use Forwext\Core\Auth\Password\PasswordHasher;
use Forwext\Core\Auth\Remember\RememberTokenService;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
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
    public function testSuccessfulLoginCreatesDeviceSessionHistoryAndKeepsVersionDuringRehash(): void
    {
        $clock = new LoginFrozenClock('2026-09-14 23:00:00');
        $user = self::user(UserStatus::Active, $clock->now());
        $users = new LoginMemoryUserRepository($user);
        $credentials = new LoginMemoryCredentialStore(new CredentialRecord(
            $user->id(),
            'stored-hash',
            7,
            $clock->now(),
        ));
        $hasher = new LoginFakeHasher(true, true);
        $history = new LoginMemoryHistory();
        $sessions = new AuthSessionManager(new LoginMemorySessionStore($clock), $credentials, 7200, $clock);
        $service = new AuthenticationService(
            $users,
            $credentials,
            $hasher,
            new AuthenticationFingerprint(new LoginSecretStore([
                'authentication.fingerprint_key' => str_repeat('f', 40),
            ])),
            new LoginAllowingLimiter(),
            new LoginMemoryDeviceRepository($clock),
            $sessions,
            new RememberTokenService(new LoginDatabase(), $credentials),
            $history,
            clock: $clock,
        );

        $result = $service->login(new LoginRequest(
            'active_user',
            'correct-password',
            '203.0.113.20',
            'Forwext Test Browser/1.0',
        ));

        self::assertSame($user->id()->value(), $result->userId->value());
        self::assertStringStartsWith('s_', $result->sessionId);
        self::assertNull($result->rememberToken);
        self::assertSame(1, $credentials->rehashCalls);
        self::assertSame(7, $credentials->find($user->id())?->version);
        self::assertSame([LoginOutcome::Success], $history->outcomes);
    }

    public function testUnknownIdentifierUsesDummyVerificationAndReturnsGenericFailure(): void
    {
        $clock = new LoginFrozenClock('2026-09-14 23:00:00');
        $credentials = new LoginMemoryCredentialStore();
        $hasher = new LoginFakeHasher(false, false);
        $history = new LoginMemoryHistory();
        $service = $this->service(
            new LoginMemoryUserRepository(),
            $credentials,
            $hasher,
            $history,
            $clock,
        );

        try {
            $service->login(new LoginRequest(
                'missing@example.com',
                'wrong-password',
                '203.0.113.21',
                'Forwext Test Browser/1.0',
            ));
            self::fail('Expected authentication rejection.');
        } catch (AuthenticationRejectedException $exception) {
            self::assertSame('Authentication failed.', $exception->getMessage());
        }

        self::assertSame(1, $hasher->dummyCalls);
        self::assertSame([LoginOutcome::InvalidCredentials], $history->outcomes);
    }

    public function testUnavailableAccountAndBadPasswordShareGenericPublicFailure(): void
    {
        $clock = new LoginFrozenClock('2026-09-14 23:00:00');
        $banned = self::user(UserStatus::Banned, $clock->now());
        $credentials = new LoginMemoryCredentialStore(new CredentialRecord(
            $banned->id(),
            'stored-hash',
            1,
            $clock->now(),
        ));
        $history = new LoginMemoryHistory();
        $service = $this->service(
            new LoginMemoryUserRepository($banned),
            $credentials,
            new LoginFakeHasher(true, false),
            $history,
            $clock,
        );

        $this->expectException(AuthenticationRejectedException::class);
        try {
            $service->login(new LoginRequest(
                'active_user',
                'correct-password',
                '203.0.113.22',
                'Forwext Test Browser/1.0',
            ));
        } finally {
            self::assertSame([LoginOutcome::AccountUnavailable], $history->outcomes);
        }
    }

    private function service(
        LoginMemoryUserRepository $users,
        LoginMemoryCredentialStore $credentials,
        LoginFakeHasher $hasher,
        LoginMemoryHistory $history,
        Clock $clock,
    ): AuthenticationService {
        return new AuthenticationService(
            $users,
            $credentials,
            $hasher,
            new AuthenticationFingerprint(new LoginSecretStore([
                'authentication.fingerprint_key' => str_repeat('f', 40),
            ])),
            new LoginAllowingLimiter(),
            new LoginMemoryDeviceRepository($clock),
            new AuthSessionManager(new LoginMemorySessionStore($clock), $credentials, 7200, $clock),
            new RememberTokenService(new LoginDatabase(), $credentials),
            $history,
            clock: $clock,
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

final class LoginMemoryUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $users = [];

    public function __construct(?User $user = null)
    {
        if ($user !== null) {
            $this->users[$user->id()->value()] = $user;
        }
    }

    public function find(EntityId $id): ?User
    {
        return $this->users[$id->value()] ?? null;
    }

    public function findByUsername(Username $username): ?User
    {
        foreach ($this->users as $user) {
            if ($user->username()->key() === $username->key()) {
                return $user;
            }
        }
        return null;
    }

    public function findByEmail(EmailAddress $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->email()->key() === $email->key()) {
                return $user;
            }
        }
        return null;
    }

    public function save(User $user): void
    {
        $this->users[$user->id()->value()] = $user;
    }

    public function history(EntityId $id, int $limit = 100, int $offset = 0): array
    {
        return [];
    }
}

final class LoginMemoryCredentialStore implements CredentialStore
{
    private ?CredentialRecord $record;
    public int $rehashCalls = 0;

    public function __construct(?CredentialRecord $record = null)
    {
        $this->record = $record;
    }

    public function find(EntityId $userId): ?CredentialRecord
    {
        return $this->record !== null && $this->record->userId->value() === $userId->value()
            ? $this->record
            : null;
    }

    public function create(EntityId $userId, string $passwordHash, DateTimeImmutable $changedAt): CredentialRecord
    {
        return $this->record = new CredentialRecord($userId, $passwordHash, 1, $changedAt);
    }

    public function rehash(EntityId $userId, int $expectedVersion, string $passwordHash): CredentialRecord
    {
        ++$this->rehashCalls;
        $changedAt = $this->record?->passwordChangedAt ?? new DateTimeImmutable('@0');
        return $this->record = new CredentialRecord($userId, $passwordHash, $expectedVersion, $changedAt);
    }

    public function replacePassword(
        EntityId $userId,
        int $expectedVersion,
        string $passwordHash,
        DateTimeImmutable $changedAt,
    ): CredentialRecord {
        return $this->record = new CredentialRecord($userId, $passwordHash, $expectedVersion + 1, $changedAt);
    }
}

final class LoginFakeHasher implements PasswordHasher
{
    public int $dummyCalls = 0;

    public function __construct(
        private readonly bool $verifyResult,
        private readonly bool $rehashResult,
    ) {
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        return 'new-hash';
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        return $this->verifyResult;
    }

    public function needsRehash(string $hash): bool
    {
        return $this->rehashResult;
    }

    public function dummyVerify(#[SensitiveParameter] string $password): void
    {
        ++$this->dummyCalls;
    }
}

final class LoginAllowingLimiter implements AuthenticationRateLimiter
{
    public function consume(string $fingerprint, int $limit, int $windowSeconds, DateTimeImmutable $now): bool
    {
        return true;
    }
}

final class LoginMemoryDeviceRepository implements DeviceRepository
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function touch(
        EntityId $userId,
        ?string $presentedDeviceId,
        string $userAgentFingerprint,
        string $ipFingerprint,
        DateTimeImmutable $now,
    ): DeviceRecord {
        return new DeviceRecord(
            $presentedDeviceId ?? str_repeat('d', 32),
            $userId,
            $userAgentFingerprint,
            $ipFingerprint,
            $this->clock->now(),
            $this->clock->now(),
        );
    }

    public function revoke(EntityId $userId, string $deviceId, DateTimeImmutable $now): bool
    {
        return true;
    }
}

final class LoginMemoryHistory implements LoginHistoryRecorder
{
    /** @var list<LoginOutcome> */
    public array $outcomes = [];

    public function record(
        ?EntityId $userId,
        string $identityFingerprint,
        string $ipFingerprint,
        string $deviceFingerprint,
        LoginOutcome $outcome,
        DateTimeImmutable $occurredAt,
    ): void {
        $this->outcomes[] = $outcome;
    }
}

final class LoginMemorySessionStore implements SessionStore
{
    /** @var array<string, SessionRecord> */
    private array $records = [];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function read(string $sessionId): ?SessionRecord
    {
        return $this->records[$sessionId] ?? null;
    }

    public function write(string $sessionId, string $payload, int $ttlSeconds): void
    {
        $this->records[$sessionId] = new SessionRecord(
            $payload,
            $this->clock->now()->modify('+' . $ttlSeconds . ' seconds'),
        );
    }

    public function delete(string $sessionId): bool
    {
        if (!isset($this->records[$sessionId])) {
            return false;
        }
        unset($this->records[$sessionId]);
        return true;
    }

    public function collectGarbage(int $limit = 1000): int
    {
        return 0;
    }
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
    /** @param array<string, string> $values */
    public function __construct(private array $values)
    {
    }
    public function has(string $name): bool { return isset($this->values[$name]); }
    public function get(string $name): ?string { return $this->values[$name] ?? null; }
    public function set(string $name, string $value): void { $this->values[$name] = $value; }
    public function delete(string $name): bool
    {
        if (!isset($this->values[$name])) { return false; }
        unset($this->values[$name]);
        return true;
    }
    public function all(): array { return $this->values; }
}

final class LoginFrozenClock implements Clock
{
    private readonly DateTimeImmutable $time;
    public function __construct(string $time) { $this->time = new DateTimeImmutable($time, new DateTimeZone('UTC')); }
    public function now(): DateTimeImmutable { return $this->time; }
}
