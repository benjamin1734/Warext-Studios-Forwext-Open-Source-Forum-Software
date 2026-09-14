<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Queue;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Queue\DatabaseQueueDriver;
use Forwext\Core\Queue\QueueName;
use Forwext\Core\Queue\RedisQueueDriver;
use Forwext\Core\Redis\RedisScriptClient;
use Forwext\Database\Migrations\Core\CreateQueueSchedulerRealtimeTables;
use PHPUnit\Framework\TestCase;

final class QueueRuntimeTest extends TestCase
{
    public function testDatabaseQueueReservationUsesRowLockAndOwnershipToken(): void
    {
        $clock = new QueueFrozenClock(new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC')));
        $database = new RecordingQueueDatabase($clock->now());
        $driver = new DatabaseQueueDriver($database, $clock);

        $reservation = $driver->reserve(QueueName::fromString('critical'), 90);

        self::assertNotNull($reservation);
        self::assertSame(1, $reservation->job->attempts);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $reservation->token);
        self::assertTrue($database->sawSkipLocked);
        self::assertTrue($database->sawTransactionRequiredQuery);

        $driver->acknowledge($reservation);
        self::assertSame($reservation->token, $database->lastAcknowledgementToken);
    }

    public function testRedisQueueUsesAtomicScriptsSameHashSlotAndPreservesBinaryPayload(): void
    {
        $clock = new QueueFrozenClock(new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC')));
        $redis = new ScriptedRedisQueueClient();
        $driver = new RedisQueueDriver($redis, clock: $clock);
        $queue = QueueName::fromString('critical');
        $payload = "\x00\xFFpayload";

        $driver->push($queue, 'mail.send', $payload, 3);
        $reservation = $driver->reserve($queue, 60);

        self::assertNotNull($reservation);
        self::assertSame($payload, $reservation->job->payload);
        self::assertSame(1, $reservation->job->attempts);
        foreach ($redis->scriptKeySets as $keys) {
            foreach ($keys as $key) {
                self::assertStringContainsString('{critical}', $key);
            }
        }

        $driver->acknowledge($reservation);
        self::assertGreaterThanOrEqual(3, count($redis->scripts));
    }

    public function testQueueSchedulerRealtimeMigrationCreatesAndVerifiesFourTables(): void
    {
        $database = new RecordingQueueDatabase(
            new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC')),
        );
        $database->fetchValueResult = 4;
        $migration = new CreateQueueSchedulerRealtimeTables();
        $context = new MigrationContext($database);

        $migration->up($context);
        $verification = $migration->verify($context);

        self::assertTrue($verification->isPassed());
        self::assertSame(4, $database->createTableQueries);
    }
}

final class QueueFrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

final class RecordingQueueDatabase implements TransactionalQueryExecutor
{
    public bool $sawSkipLocked = false;
    public bool $sawTransactionRequiredQuery = false;
    public ?string $lastAcknowledgementToken = null;
    public int $createTableQueries = 0;
    public mixed $fetchValueResult = null;
    private int $transactionDepth = 0;

    public function __construct(private readonly DateTimeImmutable $time)
    {
    }

    public function execute(CompiledQuery $query): int
    {
        if (str_starts_with($query->sql, 'CREATE TABLE IF NOT EXISTS')) {
            ++$this->createTableQueries;
        }
        if (str_starts_with($query->sql, 'DELETE FROM `forwext_jobs`')) {
            $token = $query->parameters['reservation_token'] ?? null;
            if (is_string($token)) {
                $this->lastAcknowledgementToken = $token;
            }
        }
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        if (str_contains($query->sql, '`attempts` >= `max_attempts`')) {
            return null;
        }
        if (str_contains($query->sql, 'FOR UPDATE SKIP LOCKED')) {
            $this->sawSkipLocked = true;
            $this->sawTransactionRequiredQuery = $query->requiresTransaction;
            $date = $this->time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            return [
                'job_id' => str_repeat('a', 32),
                'queue_name' => 'critical',
                'job_type' => 'mail.send',
                'payload' => 'payload',
                'attempts' => '0',
                'max_attempts' => '3',
                'available_at_utc' => $date,
                'created_at_utc' => $date,
            ];
        }
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return $this->fetchValueResult;
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function transaction(Closure $callback): mixed
    {
        ++$this->transactionDepth;
        try {
            return $callback($this);
        } finally {
            --$this->transactionDepth;
        }
    }
}

final class ScriptedRedisQueueClient implements RedisScriptClient
{
    /** @var list<string> */
    public array $scripts = [];
    /** @var list<list<string>> */
    public array $scriptKeySets = [];
    private ?string $jobJson = null;

    public function evaluate(string $script, array $keys, array $arguments = []): mixed
    {
        $this->scripts[] = $script;
        $this->scriptKeySets[] = $keys;

        if (str_contains($script, "redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])")) {
            $json = $arguments[1] ?? null;
            if (is_string($json)) {
                $this->jobJson = $json;
            }
            return 1;
        }

        if (str_contains($script, "local expired = redis.call('ZRANGEBYSCORE'")) {
            if ($this->jobJson === null) {
                return null;
            }
            $decoded = json_decode($this->jobJson, true, 32, JSON_THROW_ON_ERROR);
            $decoded['attempts'] = 1;
            $decoded['reservation_token'] = $arguments[1] ?? null;
            $decoded['reserved_until_ms'] = $arguments[2] ?? null;
            $this->jobJson = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            return $this->jobJson;
        }

        return 1;
    }

    public function get(string $key): ?string
    {
        return null;
    }

    public function set(string $key, string $value, ?int $ttlSeconds = null): void
    {
    }

    public function setIfAbsent(string $key, string $value, int $ttlMilliseconds): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function addSetMember(string $key, string $member, ?int $ttlSeconds = null): void
    {
    }

    public function removeSetMember(string $key, string $member): bool
    {
        return true;
    }

    public function setMembers(string $key): array
    {
        return [];
    }

    public function deleteIfValueMatches(string $key, string $expectedValue): bool
    {
        return true;
    }
}
