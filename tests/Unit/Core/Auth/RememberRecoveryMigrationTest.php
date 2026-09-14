<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Auth;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Challenge\AuthChallengeGrant;
use Forwext\Core\Auth\Challenge\AuthChallengePurpose;
use Forwext\Core\Auth\Challenge\AuthChallengeTokenStore;
use Forwext\Core\Auth\Credential\CredentialRecord;
use Forwext\Core\Auth\Credential\CredentialStore;
use Forwext\Core\Auth\Password\PasswordHasher;
use Forwext\Core\Auth\Password\PasswordResetService;
use Forwext\Core\Auth\Remember\RememberTokenService;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateAuthenticationRuntimeTables;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class RememberRecoveryMigrationTest extends TestCase
{
    public function testRememberTokenIsHashOnlyRotatesAndReplayRevokesFamily(): void
    {
        $now = new DateTimeImmutable('2026-09-14 23:30:00', new DateTimeZone('UTC'));
        $userId = UserId::fromStored(str_repeat('a', 32));
        $credentials = new RecoveryCredentialStore(new CredentialRecord(
            $userId,
            'stored-hash',
            3,
            $now,
        ));
        $database = new RecoveryRecordingDatabase();
        $service = new RememberTokenService($database, $credentials, 2592000);
        $deviceId = str_repeat('d', 32);

        $raw = $service->issue($userId, $deviceId, 3, $now);
        [$selector, $validator] = explode('.', $raw, 2);
        $insert = $database->executed[0];
        self::assertSame(hash('sha256', $selector), $insert->parameters['selector_hash'] ?? null);
        self::assertSame(hash('sha256', $validator), $insert->parameters['validator_hash'] ?? null);
        self::assertFalse(in_array($raw, $insert->parameters, true));

        $database->fetchOneResponses[] = [
            'validator_hash' => hash('sha256', $validator),
            'family_id' => $insert->parameters['family_id'],
            'user_id' => $userId->value(),
            'device_id' => $deviceId,
            'credential_version' => 3,
            'expires_at_utc' => $insert->parameters['expires_at_utc'],
            'consumed_at_utc' => null,
            'revoked_at_utc' => null,
        ];
        $grant = $service->consumeAndRotate($raw, $now->modify('+1 minute'));
        self::assertNotNull($grant);
        self::assertNotSame($raw, $grant->replacementToken);
        self::assertSame($userId->value(), $grant->userId->value());

        $database->fetchOneResponses[] = [
            'validator_hash' => hash('sha256', $validator),
            'family_id' => $insert->parameters['family_id'],
            'user_id' => $userId->value(),
            'device_id' => $deviceId,
            'credential_version' => 3,
            'expires_at_utc' => $insert->parameters['expires_at_utc'],
            'consumed_at_utc' => '2026-09-14 23:31:00.000000',
            'revoked_at_utc' => null,
        ];
        self::assertNull($service->consumeAndRotate($raw, $now->modify('+2 minutes')));
        self::assertTrue($database->hasSqlFragment('WHERE `family_id` = :family_id'));
    }

    public function testPasswordResetIncrementsCredentialVersionAndRevokesRememberTokens(): void
    {
        $clock = new RecoveryFrozenClock('2026-09-14 23:30:00');
        $userId = UserId::fromStored(str_repeat('b', 32));
        $credentials = new RecoveryCredentialStore(new CredentialRecord(
            $userId,
            'old-hash',
            4,
            $clock->now(),
        ));
        $database = new RecoveryRecordingDatabase();
        $remember = new RememberTokenService($database, $credentials);
        $challenges = new RecoveryChallengeStore(new AuthChallengeGrant(
            $userId,
            AuthChallengePurpose::PasswordReset,
        ));
        $reset = new PasswordResetService(
            $database,
            $credentials,
            new RecoveryHasher(),
            $challenges,
            $remember,
            3600,
            $clock,
        );

        self::assertTrue($reset->reset('reset-token', 'New secure password 123!'));
        self::assertSame(5, $credentials->find($userId)?->version);
        self::assertTrue($database->hasSqlFragment('WHERE `user_id` = :user_id AND `revoked_at_utc` IS NULL'));
    }

    public function testAuthenticationMigrationCreatesRuntimeTablesAndDropsLegacyRawSessionId(): void
    {
        $database = new RecoveryRecordingDatabase();
        $database->fetchValues = [1, 6, 0];
        $migration = new CreateAuthenticationRuntimeTables();
        $context = new MigrationContext($database);

        $migration->up($context);
        $verification = $migration->verify($context);

        self::assertTrue($verification->isPassed());
        self::assertSame(6, $database->createTableQueries);
        self::assertTrue($database->hasSqlFragment('ALTER TABLE `forwext_sessions` DROP COLUMN `session_id`'));
    }
}

final class RecoveryCredentialStore implements CredentialStore
{
    public function __construct(private ?CredentialRecord $record = null)
    {
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

final class RecoveryChallengeStore implements AuthChallengeTokenStore
{
    public function __construct(private ?AuthChallengeGrant $grant = null)
    {
    }

    public function issue(
        EntityId $userId,
        AuthChallengePurpose $purpose,
        DateTimeImmutable $now,
        int $ttlSeconds,
    ): string {
        return 'issued-challenge';
    }

    public function consume(
        string $token,
        AuthChallengePurpose $purpose,
        DateTimeImmutable $now,
    ): ?AuthChallengeGrant {
        $grant = $this->grant;
        $this->grant = null;
        return $grant !== null && $grant->purpose === $purpose ? $grant : null;
    }
}

final class RecoveryHasher implements PasswordHasher
{
    public function hash(#[SensitiveParameter] string $password): string
    {
        return 'new-hash';
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        return true;
    }

    public function needsRehash(string $hash): bool
    {
        return false;
    }

    public function dummyVerify(#[SensitiveParameter] string $password): void
    {
    }
}

final class RecoveryRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executed = [];
    /** @var list<array<string, mixed>|null> */
    public array $fetchOneResponses = [];
    /** @var list<mixed> */
    public array $fetchValues = [];
    public int $createTableQueries = 0;
    private int $depth = 0;

    public function execute(CompiledQuery $query): int
    {
        $this->executed[] = $query;
        if (str_starts_with($query->sql, 'CREATE TABLE IF NOT EXISTS')) {
            ++$this->createTableQueries;
            return 0;
        }
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return array_shift($this->fetchOneResponses);
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return array_shift($this->fetchValues);
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    public function transaction(Closure $callback): mixed
    {
        ++$this->depth;
        try {
            return $callback($this);
        } finally {
            --$this->depth;
        }
    }

    public function hasSqlFragment(string $fragment): bool
    {
        foreach ($this->executed as $query) {
            if (str_contains($query->sql, $fragment)) {
                return true;
            }
        }
        return false;
    }
}

final class RecoveryFrozenClock implements Clock
{
    private readonly DateTimeImmutable $time;

    public function __construct(string $time)
    {
        $this->time = new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
