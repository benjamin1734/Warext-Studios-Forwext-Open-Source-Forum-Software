<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Moderation\Oversight;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
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
use Forwext\Core\Forum\Moderation\ModerationAuditAction;
use Forwext\Core\Forum\Moderation\ModerationAuditEvent;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Moderation\Oversight\DatabaseModerationOversightStore;
use Forwext\Core\Moderation\Oversight\ModerationOversightHasher;
use Forwext\Core\Moderation\Oversight\ModerationOversightService;
use Forwext\Core\Moderation\Oversight\ModerationOversightStore;
use Forwext\Core\Moderation\Oversight\ModerationOversightVerifier;
use Forwext\Core\Moderation\Oversight\OversightAnomalyFlag;
use Forwext\Core\Moderation\Oversight\OversightAnomalySeverity;
use Forwext\Core\Moderation\Oversight\OversightChainState;
use Forwext\Core\Moderation\Oversight\OversightEntry;
use Forwext\Core\Moderation\Oversight\OversightReviewCase;
use Forwext\Core\Moderation\Oversight\OversightReviewRepository;
use Forwext\Core\Moderation\Oversight\OversightSelfReviewDeniedException;
use PHPUnit\Framework\TestCase;

final class OversightSystemTest extends TestCase
{
    public function testHasherRedactsSensitiveSnapshotBeforeIndependentPayloadHashing(): void
    {
        $event = $this->event($this->id('1'), [
            'password' => 'never-chain-this',
            'client_ip' => '203.0.113.9',
            'safe' => 'visible',
        ], []);
        $json = (new ModerationOversightHasher())->payloadJson($event);

        self::assertStringNotContainsString('never-chain-this', $json);
        self::assertStringNotContainsString('203.0.113.9', $json);
        self::assertStringContainsString('[REDACTED]', $json);
        self::assertStringContainsString('"safe":"visible"', $json);
    }

    public function testDatabaseAppendLocksChainStateAndAdvancesHashAtomically(): void
    {
        $database = new OversightRecordingDatabase();
        $database->fetchOneQueue[] = [
            'last_sequence' => 0,
            'last_hash' => str_repeat('0', 64),
        ];
        $store = new DatabaseModerationOversightStore($database);
        $event = $this->event($this->id('1'), [], ['locked' => true]);

        $entry = $database->transaction(static fn () => $store->append($event));

        self::assertSame(1, $entry->sequence);
        self::assertCount(1, $database->fetchOneQueries);
        self::assertStringContainsString('FOR UPDATE', $database->fetchOneQueries[0]->sql);
        self::assertCount(2, $database->executedQueries);
        self::assertStringContainsString('forwext_moderation_oversight_entries', $database->executedQueries[0]->sql);
        self::assertStringContainsString('forwext_moderation_oversight_chain_state', $database->executedQueries[1]->sql);
        self::assertSame($entry->chainHash, $database->executedQueries[1]->parameters['next_hash']);
    }

    public function testVerifierDetectsTamperedPayloadEvenWhenStoredHashesWereNotUpdated(): void
    {
        $event = $this->event($this->id('1'), [], ['locked' => true]);
        $hasher = new ModerationOversightHasher();
        $payload = $hasher->payloadJson($event);
        $payloadHash = $hasher->payloadHash($payload);
        $previous = str_repeat('0', 64);
        $chain = $hasher->chainHash(1, $previous, $payloadHash);
        $tampered = new OversightEntry(
            1,
            $event->auditId,
            $event->actorUserId,
            $event->action->value,
            $event->targetType,
            $event->targetId,
            $event->requestId->value(),
            '{"tampered":true}',
            $payloadHash,
            $previous,
            $chain,
            $event->occurredAt,
        );
        $store = new OversightMemoryStore([$tampered], new OversightChainState(1, $chain));

        $result = (new ModerationOversightVerifier($store))->verify();

        self::assertFalse($result->valid);
        self::assertSame('payload_hash_mismatch', $result->error);
    }

    public function testReviewerCannotOpenCaseOrFlagAgainstOwnModerationEntry(): void
    {
        $actor = $this->id('1');
        $entry = $this->entryForActor($actor);
        $store = new OversightMemoryStore([$entry], new OversightChainState(1, $entry->chainHash));
        $reviews = new OversightMemoryReviewRepository();
        $service = new ModerationOversightService(
            new OversightTransactionDatabase(),
            $store,
            $reviews,
            new ModerationOversightVerifier($store),
            $this->gate($actor, ['audit.view', 'audit.review']),
        );

        try {
            $service->openCase($entry->sourceAuditId, 'Own action must not be self-reviewed.');
            self::fail('Self review must be denied.');
        } catch (OversightSelfReviewDeniedException) {
            self::assertSame([], $reviews->cases);
        }

        $this->expectException(OversightSelfReviewDeniedException::class);
        try {
            $service->flag(
                $entry->sourceAuditId,
                'self.review',
                OversightAnomalySeverity::High,
                'Own action must not be self-flagged.',
            );
        } finally {
            self::assertSame([], $reviews->flags);
        }
    }

    public function testIndependentReviewerCanOpenCaseForAnotherModeratorsEntry(): void
    {
        $reviewer = $this->id('1');
        $entry = $this->entryForActor($this->id('2'));
        $store = new OversightMemoryStore([$entry], new OversightChainState(1, $entry->chainHash));
        $reviews = new OversightMemoryReviewRepository();
        $service = new ModerationOversightService(
            new OversightTransactionDatabase(),
            $store,
            $reviews,
            new ModerationOversightVerifier($store),
            $this->gate($reviewer, ['audit.view', 'audit.review']),
        );

        $case = $service->openCase($entry->sourceAuditId, 'Review this action.');

        self::assertSame($reviewer->value(), $case->openedByUserId->value());
        self::assertSame($entry->sourceAuditId->value(), $case->sourceAuditId->value());
        self::assertCount(1, $reviews->cases);
    }

    private function entryForActor(EntityId $actor): OversightEntry
    {
        $event = $this->event($actor, [], ['locked' => true]);
        $hasher = new ModerationOversightHasher();
        $payload = $hasher->payloadJson($event);
        $payloadHash = $hasher->payloadHash($payload);
        $previous = str_repeat('0', 64);
        return new OversightEntry(
            1,
            $event->auditId,
            $actor,
            $event->action->value,
            $event->targetType,
            $event->targetId,
            $event->requestId->value(),
            $payload,
            $payloadHash,
            $previous,
            $hasher->chainHash(1, $previous, $payloadHash),
            $event->occurredAt,
        );
    }

    private function event(EntityId $actor, array $before, array $after): ModerationAuditEvent
    {
        return new ModerationAuditEvent(
            ModerationAuditEvent::generateId(),
            $actor,
            ModerationAuditAction::ThreadLock,
            'thread',
            $this->id('a')->value(),
            $this->id('b'),
            ModerationReasonCode::fromString('oversight.test'),
            ModerationRequestId::fromString('req-oversight-test'),
            $before,
            $after,
            $this->time('2026-09-18 14:00:00.000000'),
        );
    }

    /** @param list<string> $permissions */
    private function gate(EntityId $actor, array $permissions): PermissionGate
    {
        return new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new OversightPermissionRepository($actor, $permissions)),
                new OversightAssignmentProvider(new UserAccessAssignment($actor, $this->id('f'))),
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

final class OversightRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchOneQueries = [];
    /** @var list<array<string,mixed>|null> */
    public array $fetchOneQueue = [];
    private bool $inside = false;

    public function execute(CompiledQuery $query): int { $this->executedQueries[] = $query; return 1; }
    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->fetchOneQueries[] = $query;
        return array_shift($this->fetchOneQueue);
    }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->inside; }
    public function transaction(Closure $callback): mixed
    {
        $previous = $this->inside;
        $this->inside = true;
        try { return $callback($this); } finally { $this->inside = $previous; }
    }
}

final class OversightTransactionDatabase implements TransactionalQueryExecutor
{
    private bool $inside = false;
    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->inside; }
    public function transaction(Closure $callback): mixed
    {
        $previous = $this->inside;
        $this->inside = true;
        try { return $callback($this); } finally { $this->inside = $previous; }
    }
}

final class OversightMemoryStore implements ModerationOversightStore
{
    /** @param list<OversightEntry> $entries */
    public function __construct(private array $entries, private OversightChainState $state)
    {
    }

    public function append(ModerationAuditEvent $event): OversightEntry
    {
        throw new \LogicException('Not used.');
    }

    public function findByAuditId(EntityId $auditId): ?OversightEntry
    {
        foreach ($this->entries as $entry) if ($entry->sourceAuditId->equals($auditId)) return $entry;
        return null;
    }

    public function pageAfter(int $sequence, int $limit = 500): array
    {
        return array_slice(array_values(array_filter(
            $this->entries,
            static fn (OversightEntry $entry): bool => $entry->sequence > $sequence,
        )), 0, $limit);
    }

    public function recent(int $limit = 100): array
    {
        return array_slice(array_reverse($this->entries), 0, $limit);
    }

    public function chainState(): OversightChainState
    {
        return $this->state;
    }
}

final class OversightMemoryReviewRepository implements OversightReviewRepository
{
    /** @var list<OversightReviewCase> */
    public array $cases = [];
    /** @var list<OversightAnomalyFlag> */
    public array $flags = [];

    public function insertCase(OversightReviewCase $case): void { $this->cases[] = $case; }
    public function reviewCase(EntityId $caseId): ?OversightReviewCase
    {
        foreach ($this->cases as $case) if ($case->caseId->equals($caseId)) return $case;
        return null;
    }
    public function openCases(int $limit = 100): array { return array_slice($this->cases, 0, $limit); }
    public function resolveCase(EntityId $caseId, EntityId $actorUserId, string $resolution, DateTimeImmutable $at): void {}

    public function insertFlag(OversightAnomalyFlag $flag): void { $this->flags[] = $flag; }
    public function flag(EntityId $flagId): ?OversightAnomalyFlag
    {
        foreach ($this->flags as $flag) if ($flag->flagId->equals($flagId)) return $flag;
        return null;
    }
    public function openFlags(int $limit = 100): array { return array_slice($this->flags, 0, $limit); }
    public function resolveFlag(EntityId $flagId, EntityId $actorUserId, string $resolution, DateTimeImmutable $at): void {}
}

final readonly class OversightAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment) {}
    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final readonly class OversightPermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $permissions */
    public function __construct(private EntityId $actor, private array $permissions) {}

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($nodeId !== null || !$assignment->userId()->equals($this->actor)
            || !in_array($key->value(), $this->permissions, true)
        ) {
            return [];
        }
        return [new PermissionRule(PermissionSubjectType::User, $this->actor, PermissionEffect::Allow)];
    }
}
