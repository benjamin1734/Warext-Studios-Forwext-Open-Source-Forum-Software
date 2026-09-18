<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Audit;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditEventStore;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Audit\CoreAuditService;
use Forwext\Core\Audit\DatabaseAuditEventStore;
use Forwext\Core\Audit\SensitiveAuditRedactor;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreAuditSystemTest extends TestCase
{
    public function testSensitiveRedactorRecursivelyRemovesSecretsRawIpAndEmail(): void
    {
        $redacted = (new SensitiveAuditRedactor())->redact([
            'username' => 'member',
            'password' => 'plain-secret',
            'client_ip' => '203.0.113.9',
            'email' => 'member@example.test',
            'nested' => [
                'api_token' => 'token-value',
                'ip_fingerprint' => str_repeat('a', 64),
                'safe' => 'visible',
            ],
        ]);

        self::assertSame('member', $redacted['username']);
        self::assertSame(SensitiveAuditRedactor::REDACTED, $redacted['password']);
        self::assertSame(SensitiveAuditRedactor::REDACTED, $redacted['client_ip']);
        self::assertSame(SensitiveAuditRedactor::REDACTED, $redacted['email']);
        self::assertSame(SensitiveAuditRedactor::REDACTED, $redacted['nested']['api_token']);
        self::assertSame(str_repeat('a', 64), $redacted['nested']['ip_fingerprint']);
        self::assertSame('visible', $redacted['nested']['safe']);
    }

    public function testDatabaseStorePersistsRedactedSnapshotOnlyInsideMutationTransaction(): void
    {
        $database = new AuditRecordingDatabase();
        $store = new DatabaseAuditEventStore($database);
        $event = $this->event([
            'password' => 'never-store-this',
            'client_ip' => '198.51.100.4',
            'status' => 'old',
        ], [
            'password' => 'new-secret',
            'email_address' => 'member@example.test',
            'status' => 'new',
        ]);

        try {
            $store->append($event);
            self::fail('Out-of-transaction audit append must fail.');
        } catch (RuntimeException) {
            self::assertSame([], $database->executed);
        }

        $database->transaction(static function () use ($store, $event): void {
            $store->append($event);
        });

        self::assertCount(1, $database->executed);
        $query = $database->executed[0];
        self::assertStringContainsString('INSERT INTO forwext_core_audit_events', $query->sql);
        self::assertStringNotContainsString('never-store-this', (string) $query->parameters['before_json']);
        self::assertStringNotContainsString('new-secret', (string) $query->parameters['after_json']);
        self::assertStringNotContainsString('198.51.100.4', (string) $query->parameters['before_json']);
        self::assertStringContainsString('[REDACTED]', (string) $query->parameters['before_json']);
        self::assertStringContainsString('"status":"new"', (string) $query->parameters['after_json']);
    }

    public function testAuditServiceRequiresAuditViewPermission(): void
    {
        $actor = $this->id('1');
        $store = new AuditMemoryStore([$this->event([], [])]);
        $denied = new CoreAuditService($store, $this->gate($actor, false));

        $this->expectException(PermissionDeniedException::class);
        try {
            $denied->recent();
        } finally {
            self::assertSame(0, $store->recentCalls);
        }
    }

    public function testAuditServiceReturnsStreamWhenAuthorized(): void
    {
        $actor = $this->id('1');
        $event = $this->event([], []);
        $store = new AuditMemoryStore([$event]);
        $service = new CoreAuditService($store, $this->gate($actor, true));

        self::assertSame([$event], $service->recent());
        self::assertSame(1, $store->recentCalls);
    }

    private function event(array $before, array $after): AuditEvent
    {
        return new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $this->id('1'),
            AuditAction::fromString('forum.metadata.configuration.save'),
            'forum.metadata',
            $this->id('a')->value(),
            $this->id('b'),
            'admin.change',
            AuditRequestId::fromString('req-core-audit'),
            $before,
            $after,
            $this->time('2026-09-18 12:30:00.000000'),
        );
    }

    private function gate(EntityId $actor, bool $allow): PermissionGate
    {
        $assignment = new UserAccessAssignment($actor, $this->id('f'));
        return new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new AuditPermissionRepository($actor, $allow)),
                new AuditAssignmentProvider($assignment),
            ),
            $actor,
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class AuditRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executed = [];
    private bool $inside = false;

    public function execute(CompiledQuery $query): int
    {
        $this->executed[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->inside; }

    public function transaction(Closure $callback): mixed
    {
        $previous = $this->inside;
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = $previous;
        }
    }
}

final class AuditMemoryStore implements AuditEventStore
{
    public int $recentCalls = 0;

    /** @param list<AuditEvent> $events */
    public function __construct(private array $events)
    {
    }

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    public function recent(int $limit = 100): array
    {
        $this->recentCalls++;
        return array_slice($this->events, 0, $limit);
    }

    public function recentForTarget(string $targetType, string $targetId, int $limit = 100, ?AuditScope $scope = null): array
    {
        return [];
    }

    public function recentForActor(EntityId $actorUserId, int $limit = 100): array
    {
        return [];
    }

    public function recentForRequest(AuditRequestId $requestId, int $limit = 100): array
    {
        return [];
    }
}

final readonly class AuditAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final readonly class AuditPermissionRepository implements PermissionRuleRepository
{
    public function __construct(private EntityId $actor, private bool $allow)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (!$this->allow || $nodeId !== null || !$assignment->userId()->equals($this->actor)
            || $key->value() !== 'audit.view'
        ) {
            return [];
        }
        return [new PermissionRule(
            PermissionSubjectType::User,
            $this->actor,
            PermissionEffect::Allow,
        )];
    }
}
