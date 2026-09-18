<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Content\Ai;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Content\Ai\AiModerationAction;
use Forwext\Core\Content\Ai\AiModerationHumanOverride;
use Forwext\Core\Content\Ai\AiModerationOverrideRepository;
use Forwext\Core\Content\Ai\AiModerationOverrideService;
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
use PHPUnit\Framework\TestCase;

final class AiModerationOverrideServiceTest extends TestCase
{
    public function testOverrideMutationIsPermissionedAtomicAuditedAndDoesNotStoreRawContent(): void
    {
        $actor = EntityId::fromString(str_repeat('1', 32));
        $database = new OverrideTestDatabase();
        $repository = new OverrideMemoryRepository();
        $audit = new OverrideAuditRecorder($database);
        $service = new AiModerationOverrideService(
            $database,
            $repository,
            new PermissionGate($this->authorizer($actor, true), $actor),
            $audit,
            AuditRequestId::fromString('request-ai-1234'),
        );
        $raw = 'Sensitive forum text that must not be stored in the override row.';

        $override = $service->set(
            'forum.post',
            $raw,
            AiModerationAction::Allow,
            'Moderator reviewed the exact content.',
            null,
            $this->time(),
        );

        self::assertSame(AiModerationAction::Allow, $override->action);
        self::assertNotSame($raw, $override->contentFingerprint);
        self::assertSame(64, strlen($override->contentFingerprint));
        self::assertCount(1, $audit->events);
        self::assertTrue($audit->insideTransaction[0]);
        self::assertSame('content.ai_moderation.override.set', $audit->events[0]->action->value());
        self::assertSame($override->contentFingerprint, $audit->events[0]->targetId);
        self::assertStringNotContainsString($raw, json_encode($audit->events[0]->after, JSON_THROW_ON_ERROR));

        self::assertTrue($service->clear('forum.post', $raw, $this->time()));
        self::assertCount(2, $audit->events);
        self::assertSame('content.ai_moderation.override.clear', $audit->events[1]->action->value());
        self::assertNull($repository->active($override->contentFingerprint, $this->time()));
    }

    public function testOverrideRequiresDedicatedPermission(): void
    {
        $actor = EntityId::fromString(str_repeat('1', 32));
        $service = new AiModerationOverrideService(
            new OverrideTestDatabase(),
            new OverrideMemoryRepository(),
            new PermissionGate($this->authorizer($actor, false), $actor),
            new OverrideAuditRecorder(new OverrideTestDatabase()),
            AuditRequestId::fromString('request-ai-1234'),
        );

        $this->expectException(\Forwext\Core\Domain\Access\Permission\PermissionDeniedException::class);
        $service->set(
            'forum.post',
            'content',
            AiModerationAction::Allow,
            'No permission.',
            null,
            $this->time(),
        );
    }

    private function authorizer(EntityId $actor, bool $allow): PermissionAuthorizer
    {
        return new PermissionAuthorizer(
            new PermissionEngine(new OverridePermissionRepository($actor, $allow)),
            new OverrideAssignmentProvider($actor),
        );
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-18 20:45:00', new DateTimeZone('UTC'));
    }
}

final class OverrideMemoryRepository implements AiModerationOverrideRepository
{
    /** @var array<string,AiModerationHumanOverride> */
    private array $items = [];

    public function active(string $contentFingerprint, DateTimeImmutable $at): ?AiModerationHumanOverride
    {
        $item = $this->items[$contentFingerprint] ?? null;
        return $item !== null && $item->isActive($at) ? $item : null;
    }

    public function save(AiModerationHumanOverride $override): void
    {
        $this->items[$override->contentFingerprint] = $override;
    }

    public function delete(string $contentFingerprint): bool
    {
        if (!isset($this->items[$contentFingerprint])) {
            return false;
        }
        unset($this->items[$contentFingerprint]);
        return true;
    }
}

final class OverrideTestDatabase implements TransactionalQueryExecutor
{
    private bool $inside = false;

    public function execute(CompiledQuery $query): int { return 0; }
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

final class OverrideAuditRecorder implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events = [];
    /** @var list<bool> */
    public array $insideTransaction = [];

    public function __construct(private readonly OverrideTestDatabase $database)
    {
    }

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
        $this->insideTransaction[] = $this->database->inTransaction();
    }

    public function mutate(AuditEvent $event, callable $mutation): mixed
    {
        $result = $mutation();
        $this->append($event);
        return $result;
    }
}

final readonly class OverrideAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $actor)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $userId->equals($this->actor)
            ? new UserAccessAssignment($userId, EntityId::fromString(str_repeat('f', 32)))
            : null;
    }
}

final readonly class OverridePermissionRepository implements PermissionRuleRepository
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
        if (!$this->allow
            || $key->value() !== AiModerationOverrideService::PERMISSION
            || !$assignment->userId()->equals($this->actor)
            || $nodeId !== null
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
