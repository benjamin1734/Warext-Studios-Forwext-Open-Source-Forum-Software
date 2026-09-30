<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Auth;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\Session\AuthSessionIndexRecord;
use Forwext\Core\Auth\Session\DatabaseAuthSessionIndex;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class DatabaseAuthSessionIndexTest extends TestCase
{
    public function testRegistrationPersistsOnlyHashAndTypedMetadata(): void
    {
        $database = new AuthSessionIndexRecordingDatabase();
        $index = new DatabaseAuthSessionIndex($database);
        $record = $this->record(str_repeat('a', 64));

        $index->register($record);

        self::assertCount(1, $database->executed);
        $query = $database->executed[0];
        self::assertStringContainsString('forwext_auth_session_index', $query->sql);
        self::assertStringNotContainsString('ON DUPLICATE KEY', $query->sql);
        self::assertSame($record->sessionHash, $query->parameters['session_hash']);
        self::assertArrayNotHasKey('session_id', $query->parameters);
    }

    public function testActiveListAndRevocationAreUserBoundAndDoNotNeedRawSessionToken(): void
    {
        $database = new AuthSessionIndexRecordingDatabase();
        $database->fetchAllQueue = [[[
            'session_hash' => str_repeat('a', 64),
            'user_id' => str_repeat('b', 32),
            'device_id' => str_repeat('c', 32),
            'credential_version' => 2,
            'issued_at_utc' => '2026-09-30 10:00:00.000000',
            'expires_at_utc' => '2026-09-30 14:00:00.000000',
            'last_seen_at_utc' => '2026-09-30 11:00:00.000000',
            'revoked_at_utc' => null,
        ]]];
        $index = new DatabaseAuthSessionIndex($database);
        $userId = UserId::fromStored(str_repeat('b', 32));
        $now = $this->time('2026-09-30 12:00:00.000000');

        $sessions = $index->activeForUser($userId, $now);
        self::assertCount(1, $sessions);
        self::assertStringContainsString('user_id', $database->fetchAllQueries[0]->sql);
        self::assertStringContainsString('revoked_at_utc', $database->fetchAllQueries[0]->sql);
        self::assertStringContainsString('expires_at_utc', $database->fetchAllQueries[0]->sql);

        $database->executeResult = 1;
        self::assertTrue($index->revokeForUser($userId, str_repeat('a', 64), $now));
        $revoke = $database->executed[0];
        self::assertStringContainsString('session_hash', $revoke->sql);
        self::assertStringContainsString('user_id', $revoke->sql);
        self::assertSame(str_repeat('a', 64), $revoke->parameters['session_hash']);
        self::assertSame(str_repeat('b', 32), $revoke->parameters['user_id']);
    }

    private function record(string $hash): AuthSessionIndexRecord
    {
        return new AuthSessionIndexRecord(
            $hash,
            UserId::fromStored(str_repeat('b', 32)),
            str_repeat('c', 32),
            1,
            $this->time('2026-09-30 10:00:00.000000'),
            $this->time('2026-09-30 14:00:00.000000'),
            $this->time('2026-09-30 11:00:00.000000'),
        );
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class AuthSessionIndexRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executed = [];
    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];
    /** @var list<list<array<string,mixed>>> */
    public array $fetchAllQueue = [];
    public int $executeResult = 1;

    public function execute(CompiledQuery $query): int { $this->executed[] = $query; return $this->executeResult; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return array_shift($this->fetchAllQueue) ?? [];
    }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { return $callback($this); }
}
