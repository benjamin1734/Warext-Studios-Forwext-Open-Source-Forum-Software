<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Credential\CredentialRecord;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Auth\Password\NativePasswordHasher;
use Forwext\Core\Auth\Password\PasswordHashPolicy;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Session\DatabaseSessionStore;
use Forwext\Core\Session\SessionRecord;
use Forwext\Core\Session\SessionStore;
use PHPUnit\Framework\TestCase;
use Closure;

final class PasswordSessionTest extends TestCase
{
    public function testPasswordHasherValidatesHashesAndVerifiesWithoutPlaintextPersistence(): void
    {
        $policy = new PasswordHashPolicy(
            minimumCharacters: 8,
            argonMemoryCost: 8192,
            argonTimeCost: 1,
            argonThreads: 1,
            bcryptCost: 10,
        );
        $hasher = new NativePasswordHasher($policy);
        $password = 'Correct horse 123!';
        $hash = $hasher->hash($password);

        self::assertNotSame($password, $hash);
        self::assertTrue($hasher->verify($password, $hash));
        self::assertFalse($hasher->verify('wrong password', $hash));
    }

    public function testAuthSessionRotationInvalidatesOldSessionAndCredentialVersionInvalidatesCurrentSession(): void
    {
        $clock = new AuthFrozenClock('2026-09-14 22:00:00');
        $credentials = new AuthMemoryCredentialStore();
        $userId = UserId::fromStored(str_repeat('a', 32));
        $credentials->seed(new CredentialRecord(
            $userId,
            '$2y$12$placeholder',
            1,
            $clock->now(),
        ));
        $sessions = new AuthMemorySessionStore($clock);
        $manager = new AuthSessionManager($sessions, $credentials, 7200, $clock);

        $first = $manager->create($userId, str_repeat('b', 32), 1);
        self::assertNotNull($manager->resolve($first));

        $second = $manager->rotate($first);
        self::assertNull($manager->resolve($first));
        self::assertNotNull($manager->resolve($second));
        self::assertNotSame($first, $second);

        $credentials->seed(new CredentialRecord(
            $userId,
            '$2y$12$replacement',
            2,
            $clock->now(),
        ));
        self::assertNull($manager->resolve($second));
    }

    public function testDatabaseSessionStorePersistsOnlySessionHashNotRawSessionId(): void
    {
        $database = new AuthRecordingDatabase();
        $clock = new AuthFrozenClock('2026-09-14 22:00:00');
        $store = new DatabaseSessionStore($database, $clock);
        $raw = 's_' . str_repeat('A', 43);

        $store->write($raw, '{"user":"opaque"}', 7200);

        $query = $database->executed[0];
        self::assertStringNotContainsString('`session_id`', $query->sql);
        self::assertSame(hash('sha256', $raw), $query->parameters['session_hash'] ?? null);
        self::assertFalse(in_array($raw, $query->parameters, true));
    }
}

final class AuthMemoryCredentialStore implements CredentialStore
{
    /** @var array<string, CredentialRecord> */
    private array $records = [];

    public function seed(CredentialRecord $record): void
    {
        $this->records[$record->userId->value()] = $record;
    }

    public function find(EntityId $userId): ?CredentialRecord
    {
        return $this->records[$userId->value()] ?? null;
    }

    public function create(EntityId $userId, string $passwordHash, DateTimeImmutable $changedAt): CredentialRecord
    {
        $record = new CredentialRecord($userId, $passwordHash, 1, $changedAt);
        $this->seed($record);
        return $record;
    }

    public function rehash(EntityId $userId, int $expectedVersion, string $passwordHash): CredentialRecord
    {
        $current = $this->records[$userId->value()];
        $record = new CredentialRecord($userId, $passwordHash, $expectedVersion, $current->passwordChangedAt);
        $this->seed($record);
        return $record;
    }

    public function replacePassword(
        EntityId $userId,
        int $expectedVersion,
        string $passwordHash,
        DateTimeImmutable $changedAt,
    ): CredentialRecord {
        $record = new CredentialRecord($userId, $passwordHash, $expectedVersion + 1, $changedAt);
        $this->seed($record);
        return $record;
    }
}

final class AuthMemorySessionStore implements SessionStore
{
    /** @var array<string, SessionRecord> */
    private array $records = [];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function read(string $sessionId): ?SessionRecord
    {
        $record = $this->records[$sessionId] ?? null;
        if ($record !== null && $record->isExpired($this->clock->now())) {
            unset($this->records[$sessionId]);
            return null;
        }
        return $record;
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

final class AuthFrozenClock implements Clock
{
    private readonly DateTimeImmutable $value;

    public function __construct(string $value)
    {
        $this->value = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class AuthRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executed = [];

    public function execute(CompiledQuery $query): int
    {
        $this->executed[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return null;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback($this);
    }
}
