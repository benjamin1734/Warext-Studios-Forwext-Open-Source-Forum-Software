<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Registration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Credential\CredentialProvisioner;
use Forwext\Core\Auth\Credential\CredentialRecord;
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
use Forwext\Core\Registration\Captcha\CaptchaVerification;
use Forwext\Core\Registration\Captcha\CaptchaVerifier;
use Forwext\Core\Registration\DisposableEmailChecker;
use Forwext\Core\Registration\EmailVerificationGrant;
use Forwext\Core\Registration\EmailVerificationService;
use Forwext\Core\Registration\EmailVerificationTokenStore;
use Forwext\Core\Registration\LegalAcceptanceStore;
use Forwext\Core\Registration\LegalDocumentRequirement;
use Forwext\Core\Registration\RegistrationException;
use Forwext\Core\Registration\RegistrationFingerprint;
use Forwext\Core\Registration\RegistrationInviteStore;
use Forwext\Core\Registration\RegistrationMode;
use Forwext\Core\Registration\RegistrationPolicy;
use Forwext\Core\Registration\RegistrationRateLimiter;
use Forwext\Core\Registration\RegistrationRequest;
use Forwext\Core\Registration\RegistrationService;
use Forwext\Core\Security\Secret\SecretStore;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class RegistrationServiceTest extends TestCase
{
    public function testApprovalRegistrationCreatesCredentialPendingEmailUserAndVerificationTarget(): void
    {
        $database = new RegistrationTransactionDatabase();
        $users = new MemoryRegistrationUserRepository();
        $credentials = new MemoryRegistrationCredentialProvisioner();
        $captcha = new SuccessfulCaptchaVerifier();
        $legal = new MemoryLegalAcceptanceStore();
        $tokens = new MemoryEmailVerificationTokenStore();
        $document = new LegalDocumentRequirement('terms', '2026-09', str_repeat('a', 64));
        $service = $this->service(
            $database,
            $users,
            new RegistrationPolicy(mode: RegistrationMode::Approval, legalDocuments: [$document]),
            $captcha,
            new NeverDisposableChecker(),
            new AllowingRateLimiter(),
            new MemoryInviteStore(),
            $legal,
            $tokens,
            $credentials,
        );

        $result = $service->register(new RegistrationRequest(
            username: 'New_User',
            email: 'USER@Example.com',
            locale: 'tr-TR',
            timezone: 'Europe/Istanbul',
            clientIp: '203.0.113.10',
            captchaToken: 'captcha-ok',
            acceptedLegalVersions: ['terms' => '2026-09'],
            password: 'Correct Horse Battery Staple 1!',
        ));

        self::assertSame(UserStatus::PendingEmailVerification, $result->status);
        self::assertSame(UserStatus::PendingApproval, $tokens->issuedTarget);
        self::assertSame('verification-token', $result->emailVerificationToken);
        self::assertSame('Correct Horse Battery Staple 1!', $credentials->lastPassword);
        self::assertCount(1, $legal->records);
        self::assertSame(1, $captcha->calls);
        self::assertSame(1, $database->transactions);
        self::assertSame('user@example.com', $users->find($result->userId)?->email()->value());
    }

    public function testInviteOnlyWithoutEmailVerificationConsumesInviteAndCreatesActiveAccount(): void
    {
        $invites = new MemoryInviteStore();
        $service = $this->service(
            new RegistrationTransactionDatabase(),
            new MemoryRegistrationUserRepository(),
            new RegistrationPolicy(
                mode: RegistrationMode::InviteOnly,
                emailVerificationRequired: false,
                captchaRequired: false,
            ),
            new SuccessfulCaptchaVerifier(),
            new NeverDisposableChecker(),
            new AllowingRateLimiter(),
            $invites,
            new MemoryLegalAcceptanceStore(),
            new MemoryEmailVerificationTokenStore(),
            new MemoryRegistrationCredentialProvisioner(),
        );

        $result = $service->register(new RegistrationRequest(
            'invited_user',
            'invited@example.com',
            'en-US',
            'UTC',
            '2001:db8::5',
            inviteCode: 'VALID_INVITE_123',
            password: 'Correct Horse Battery Staple 2!',
        ));

        self::assertSame(UserStatus::Active, $result->status);
        self::assertNull($result->emailVerificationToken);
        self::assertSame(['VALID_INVITE_123'], $invites->consumedCodes);
    }

    public function testLegalVersionMismatchAndDisposableEmailFailBeforeAccountTransaction(): void
    {
        $database = new RegistrationTransactionDatabase();
        $service = $this->service(
            $database,
            new MemoryRegistrationUserRepository(),
            new RegistrationPolicy(
                captchaRequired: false,
                legalDocuments: [new LegalDocumentRequirement('privacy', 'v2', str_repeat('b', 64))],
            ),
            new SuccessfulCaptchaVerifier(),
            new NeverDisposableChecker(),
            new AllowingRateLimiter(),
            new MemoryInviteStore(),
            new MemoryLegalAcceptanceStore(),
            new MemoryEmailVerificationTokenStore(),
            new MemoryRegistrationCredentialProvisioner(),
        );

        try {
            $service->register(new RegistrationRequest(
                'legal_user',
                'legal@example.com',
                'en-US',
                'UTC',
                '203.0.113.11',
                acceptedLegalVersions: ['privacy' => 'v1'],
                password: 'Correct Horse Battery Staple 3!',
            ));
            self::fail('Expected legal acceptance rejection.');
        } catch (RegistrationException) {
            self::assertSame(0, $database->transactions);
        }

        $disposableDatabase = new RegistrationTransactionDatabase();
        $disposable = $this->service(
            $disposableDatabase,
            new MemoryRegistrationUserRepository(),
            new RegistrationPolicy(captchaRequired: false),
            new SuccessfulCaptchaVerifier(),
            new AlwaysDisposableChecker(),
            new AllowingRateLimiter(),
            new MemoryInviteStore(),
            new MemoryLegalAcceptanceStore(),
            new MemoryEmailVerificationTokenStore(),
            new MemoryRegistrationCredentialProvisioner(),
        );
        try {
            $disposable->register(new RegistrationRequest(
                'temp_user',
                'temp@example.com',
                'en-US',
                'UTC',
                '203.0.113.12',
                password: 'Correct Horse Battery Staple 4!',
            ));
            self::fail('Expected disposable-email rejection.');
        } catch (RegistrationException) {
            self::assertSame(0, $disposableDatabase->transactions);
        }
    }

    public function testMissingPasswordFailsClosed(): void
    {
        $service = $this->service(
            new RegistrationTransactionDatabase(),
            new MemoryRegistrationUserRepository(),
            new RegistrationPolicy(captchaRequired: false),
            new SuccessfulCaptchaVerifier(),
            new NeverDisposableChecker(),
            new AllowingRateLimiter(),
            new MemoryInviteStore(),
            new MemoryLegalAcceptanceStore(),
            new MemoryEmailVerificationTokenStore(),
            new MemoryRegistrationCredentialProvisioner(),
        );

        $this->expectException(RegistrationException::class);
        $service->register(new RegistrationRequest('no_password', 'no@example.com', 'en-US', 'UTC', '203.0.113.13'));
    }

    public function testEmailVerificationConsumesGrantAndTransitionsAccount(): void
    {
        $database = new RegistrationTransactionDatabase();
        $users = new MemoryRegistrationUserRepository();
        $now = new DateTimeImmutable('2026-09-14 21:00:00', new DateTimeZone('UTC'));
        $user = User::create(
            UserId::generate(),
            Username::fromString('verify_user'),
            EmailAddress::fromString('verify@example.com'),
            UserStatus::PendingEmailVerification,
            UserLocale::fromString('en-US'),
            UserTimezone::fromString('UTC'),
            $now,
        );
        $users->save($user);
        $tokens = new MemoryEmailVerificationTokenStore();
        $tokens->grant = new EmailVerificationGrant($user->id(), UserStatus::Active);
        $service = new EmailVerificationService(
            $database,
            $users,
            $tokens,
            new FrozenRegistrationClock('2026-09-14 21:01:00'),
        );

        self::assertTrue($service->verify('valid-token'));
        self::assertSame(UserStatus::Active, $users->find($user->id())?->status());
        self::assertSame(2, $user->version());
    }

    private function service(
        RegistrationTransactionDatabase $database,
        MemoryRegistrationUserRepository $users,
        RegistrationPolicy $policy,
        CaptchaVerifier $captcha,
        DisposableEmailChecker $disposable,
        RegistrationRateLimiter $limiter,
        RegistrationInviteStore $invites,
        LegalAcceptanceStore $legal,
        EmailVerificationTokenStore $tokens,
        CredentialProvisioner $credentials,
    ): RegistrationService {
        return new RegistrationService(
            $database,
            $users,
            $policy,
            $captcha,
            $disposable,
            $limiter,
            new RegistrationFingerprint(new RegistrationSecretStore([
                'registration.fingerprint_key' => str_repeat('k', 40),
            ])),
            $invites,
            $legal,
            $tokens,
            $credentials,
            new FrozenRegistrationClock('2026-09-14 21:00:00'),
        );
    }
}

final class RegistrationTransactionDatabase implements TransactionalQueryExecutor
{
    public int $transactions = 0;
    private int $depth = 0;

    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->depth > 0; }
    public function transaction(Closure $callback): mixed
    {
        ++$this->transactions;
        ++$this->depth;
        try { return $callback($this); } finally { --$this->depth; }
    }
}

final class MemoryRegistrationUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $users = [];

    public function find(EntityId $id): ?User { return $this->users[$id->value()] ?? null; }
    public function findByUsername(Username $username): ?User
    {
        foreach ($this->users as $user) {
            if ($user->username()->key() === $username->key()) { return $user; }
        }
        return null;
    }
    public function findByEmail(EmailAddress $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->email()->key() === $email->key()) { return $user; }
        }
        return null;
    }
    public function save(User $user): void
    {
        if ($user->version() === 0 || $user->pendingHistory() !== []) {
            $user->markPersisted($user->version() + 1);
        }
        $this->users[$user->id()->value()] = $user;
    }
    public function history(EntityId $id, int $limit = 100, int $offset = 0): array { return []; }
}

final class MemoryRegistrationCredentialProvisioner implements CredentialProvisioner
{
    public ?string $lastPassword = null;
    public function provision(
        EntityId $userId,
        #[SensitiveParameter] string $password,
        DateTimeImmutable $now,
    ): CredentialRecord {
        $this->lastPassword = $password;
        return new CredentialRecord($userId, '$2y$12$testhashplaceholder', 1, $now);
    }
}

final class SuccessfulCaptchaVerifier implements CaptchaVerifier
{
    public int $calls = 0;
    public function verify(string $token, string $clientIp): CaptchaVerification
    {
        ++$this->calls;
        return new CaptchaVerification(true, hostname: 'forum.example', action: 'register');
    }
}

final class NeverDisposableChecker implements DisposableEmailChecker
{
    public function isDisposable(EmailAddress $email): bool { return false; }
}

final class AlwaysDisposableChecker implements DisposableEmailChecker
{
    public function isDisposable(EmailAddress $email): bool { return true; }
}

final class AllowingRateLimiter implements RegistrationRateLimiter
{
    public function consume(
        string $scope,
        string $fingerprint,
        int $limit,
        int $windowSeconds,
        DateTimeImmutable $now,
    ): bool { return true; }
}

final class MemoryInviteStore implements RegistrationInviteStore
{
    /** @var list<string> */
    public array $consumedCodes = [];
    public function issue(int $maxUses, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now): string
    {
        return 'MEMORY_INVITE';
    }
    public function consume(string $code, DateTimeImmutable $now): bool
    {
        $this->consumedCodes[] = $code;
        return $code === 'VALID_INVITE_123';
    }
}

final class MemoryLegalAcceptanceStore implements LegalAcceptanceStore
{
    /** @var list<array{string, string}> */
    public array $records = [];
    public function record(
        EntityId $userId,
        LegalDocumentRequirement $document,
        DateTimeImmutable $acceptedAt,
        string $clientFingerprint,
    ): void { $this->records[] = [$userId->value(), $document->type]; }
}

final class MemoryEmailVerificationTokenStore implements EmailVerificationTokenStore
{
    public ?UserStatus $issuedTarget = null;
    public ?EmailVerificationGrant $grant = null;
    public function issue(
        EntityId $userId,
        UserStatus $targetStatus,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): string {
        $this->issuedTarget = $targetStatus;
        return 'verification-token';
    }
    public function consume(string $token, DateTimeImmutable $now): ?EmailVerificationGrant { return $this->grant; }
}

final class FrozenRegistrationClock implements Clock
{
    private readonly DateTimeImmutable $time;
    public function __construct(string $time) { $this->time = new DateTimeImmutable($time, new DateTimeZone('UTC')); }
    public function now(): DateTimeImmutable { return $this->time; }
}

final class RegistrationSecretStore implements SecretStore
{
    /** @param array<string, string> $values */
    public function __construct(private array $values) {}
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
