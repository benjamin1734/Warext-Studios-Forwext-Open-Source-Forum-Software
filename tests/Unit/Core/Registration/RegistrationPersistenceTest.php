<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Registration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Registration\DatabaseEmailVerificationTokenStore;
use Forwext\Core\Registration\DatabaseRegistrationInviteStore;
use Forwext\Core\Registration\DatabaseRegistrationRateLimiter;
use Forwext\Database\Migrations\Core\CreateRegistrationSecurityTables;
use PHPUnit\Framework\TestCase;

final class RegistrationPersistenceTest extends TestCase
{
    public function testMigrationCreatesAllRegistrationSecurityTablesAndIntegrityIndexes(): void
    {
        $database = new RegistrationRecordingDatabase();
        $database->fetchValues = [4, 2];
        $migration = new CreateRegistrationSecurityTables();
        $context = new MigrationContext($database);

        $migration->up($context);
        $verification = $migration->verify($context);

        self::assertTrue($verification->isPassed());
        self::assertSame(4, $database->createTableQueries);
    }

    public function testInviteIssuanceStoresOnlyHashAndConsumptionUsesRowLock(): void
    {
        $database = new RegistrationRecordingDatabase();
        $store = new DatabaseRegistrationInviteStore($database);
        $now = new DateTimeImmutable('2026-09-14 21:00:00', new DateTimeZone('UTC'));

        $code = $store->issue(2, $now->modify('+1 day'), $now);
        $insert = $database->executed[0];

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $code);
        self::assertSame(hash('sha256', $code), $insert->parameters['code_hash'] ?? null);
        self::assertFalse(in_array($code, $insert->parameters, true));

        $database->fetchOneResponses[] = [
            'invite_id' => str_repeat('a', 32),
            'uses' => 0,
            'max_uses' => 2,
            'expires_at_utc' => '2026-09-15 21:00:00.000000',
            'disabled' => 0,
        ];
        self::assertTrue($store->consume($code, $now));
        self::assertTrue($database->lastFetchOne?->requiresTransaction ?? false);
    }

    public function testEmailVerificationTokenStoresDigestAndUsesLockedConsumption(): void
    {
        $database = new RegistrationRecordingDatabase();
        $store = new DatabaseEmailVerificationTokenStore($database);
        $now = new DateTimeImmutable('2026-09-14 21:00:00', new DateTimeZone('UTC'));
        $userId = UserId::fromStored(str_repeat('b', 32));

        $token = $store->issue($userId, UserStatus::Active, $now, 3600);
        $insert = $database->executed[0];

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame(hash('sha256', $token), $insert->parameters['token_hash'] ?? null);
        self::assertFalse(in_array($token, $insert->parameters, true));

        $database->fetchOneResponses[] = [
            'user_id' => $userId->value(),
            'target_status' => UserStatus::Active->value,
            'expires_at_utc' => '2026-09-14 22:00:00.000000',
            'consumed_at_utc' => null,
        ];
        $grant = $store->consume($token, $now);

        self::assertNotNull($grant);
        self::assertSame($userId->value(), $grant->userId->value());
        self::assertSame(UserStatus::Active, $grant->targetStatus);
        self::assertTrue($database->lastFetchOne?->requiresTransaction ?? false);
    }

    public function testDatabaseRateLimiterUsesAtomicBucketIncrementAndEnforcesLimit(): void
    {
        $database = new RegistrationRecordingDatabase();
        $database->fetchValues = [1, 2];
        $limiter = new DatabaseRegistrationRateLimiter($database);
        $now = new DateTimeImmutable('2026-09-14 21:00:00', new DateTimeZone('UTC'));
        $fingerprint = str_repeat('c', 64);

        self::assertTrue($limiter->consume('registration.ip', $fingerprint, 1, 3600, $now));
        self::assertFalse($limiter->consume('registration.ip', $fingerprint, 1, 3600, $now));
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $database->executed[0]->sql);
    }
}

final class RegistrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executed = [];
    /** @var list<array<string, mixed>|null> */
    public array $fetchOneResponses = [];
    /** @var list<mixed> */
    public array $fetchValues = [];
    public int $createTableQueries = 0;
    public ?CompiledQuery $lastFetchOne = null;
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
        $this->lastFetchOne = $query;
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
}
