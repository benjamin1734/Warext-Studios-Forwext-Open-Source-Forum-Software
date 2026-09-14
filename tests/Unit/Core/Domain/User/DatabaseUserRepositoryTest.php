<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\User;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserConcurrencyException;
use Forwext\Core\Domain\User\UserCustomFieldKey;
use Forwext\Core\Domain\User\UserCustomFieldValue;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Infrastructure\User\DatabaseUserRepository;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateUserDomainTables;
use PHPUnit\Framework\TestCase;

final class DatabaseUserRepositoryTest extends TestCase
{
    public function testNewUserCustomFieldsAndHistoryPersistAtomicallyThenNoOpSaveStaysNoOp(): void
    {
        $database = new UserRepositoryRecordingDatabase();
        $repository = new DatabaseUserRepository($database);
        $user = $this->newUser();
        $user->setCustomField(
            UserCustomFieldKey::fromString('profile.favorite_game'),
            UserCustomFieldValue::string('Minecraft'),
            new DateTimeImmutable('2026-09-14 20:01:00', new DateTimeZone('UTC')),
            $user->id(),
        );

        $repository->save($user);

        self::assertSame(1, $database->transactions);
        self::assertSame(1, $database->userInserts);
        self::assertSame(1, $database->customFieldInserts);
        self::assertSame(2, $database->historyInserts);
        self::assertSame(1, $user->version());
        self::assertSame([], $user->pendingHistory());

        $repository->save($user);
        self::assertSame(1, $database->transactions);
        self::assertSame(1, $user->version());
    }

    public function testOptimisticConcurrencyFailureDoesNotAdvanceAggregateVersionOrClearHistory(): void
    {
        $database = new UserRepositoryRecordingDatabase();
        $database->userUpdateAffectedRows = 0;
        $repository = new DatabaseUserRepository($database);
        $now = new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC'));
        $user = User::hydrate(
            UserId::fromStored(str_repeat('a', 32)),
            Username::fromString('existing_user'),
            EmailAddress::fromString('existing@example.com'),
            UserStatus::Active,
            UserLocale::fromString('tr-TR'),
            UserTimezone::fromString('Europe/Istanbul'),
            [],
            $now,
            $now,
            7,
        );
        $user->changeTimezone(
            UserTimezone::fromString('UTC'),
            new DateTimeImmutable('2026-09-14 20:01:00', new DateTimeZone('UTC')),
        );

        try {
            $repository->save($user);
            self::fail('Expected optimistic concurrency failure.');
        } catch (UserConcurrencyException) {
            self::assertSame(7, $user->version());
            self::assertCount(1, $user->pendingHistory());
        }
    }

    public function testUserMigrationCreatesDomainTablesAndIdentityUniquenessIndexes(): void
    {
        $database = new UserRepositoryRecordingDatabase();
        $database->fetchValues = [3, 2];
        $migration = new CreateUserDomainTables();
        $context = new MigrationContext($database);

        $migration->up($context);
        $verification = $migration->verify($context);

        self::assertTrue($verification->isPassed());
        self::assertSame(3, $database->createTableQueries);
    }

    private function newUser(): User
    {
        $now = new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC'));
        return User::create(
            UserId::generate(),
            Username::fromString('new_user'),
            EmailAddress::fromString('new@example.com'),
            UserStatus::PendingEmailVerification,
            UserLocale::fromString('tr-TR'),
            UserTimezone::fromString('Europe/Istanbul'),
            $now,
        );
    }
}

final class UserRepositoryRecordingDatabase implements TransactionalQueryExecutor
{
    public int $transactions = 0;
    public int $userInserts = 0;
    public int $customFieldInserts = 0;
    public int $historyInserts = 0;
    public int $createTableQueries = 0;
    public int $userUpdateAffectedRows = 1;
    /** @var list<int> */
    public array $fetchValues = [];
    private int $transactionDepth = 0;

    public function execute(CompiledQuery $query): int
    {
        if (str_starts_with($query->sql, 'CREATE TABLE IF NOT EXISTS')) {
            ++$this->createTableQueries;
            return 0;
        }
        if (str_starts_with($query->sql, 'INSERT INTO `forwext_users`')) {
            ++$this->userInserts;
            return 1;
        }
        if (str_starts_with($query->sql, 'UPDATE `forwext_users`')) {
            return $this->userUpdateAffectedRows;
        }
        if (str_starts_with($query->sql, 'INSERT INTO `forwext_user_custom_field_values`')) {
            ++$this->customFieldInserts;
            return 1;
        }
        if (str_starts_with($query->sql, 'INSERT INTO `forwext_user_history`')) {
            ++$this->historyInserts;
            return 1;
        }
        return 0;
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
        return array_shift($this->fetchValues);
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function transaction(Closure $callback): mixed
    {
        ++$this->transactions;
        ++$this->transactionDepth;
        try {
            return $callback($this);
        } finally {
            --$this->transactionDepth;
        }
    }
}
