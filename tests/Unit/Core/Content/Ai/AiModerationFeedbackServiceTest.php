<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Content\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Content\Ai\AiModerationFeedback;
use Forwext\Core\Content\Ai\AiModerationFeedbackService;
use Forwext\Core\Content\Ai\AiModerationFeedbackStore;
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

final class AiModerationFeedbackServiceTest extends TestCase
{
    public function testFeedbackUsesSharedPermissionAndCentralAuditWithoutAuditNoteLeakage(): void
    {
        $actor = EntityId::fromString(str_repeat('1', 32));
        $decision = EntityId::fromString(str_repeat('2', 32));
        $store = new FeedbackMemoryStore();
        $audit = new FeedbackRecordingAudit();
        $service = new AiModerationFeedbackService(
            $store,
            $this->gate($actor, true),
            $audit,
        );

        $item = $service->report(
            $decision,
            'false_positive',
            'Sensitive moderator note stays in feedback storage only.',
            new DateTimeImmutable('2026-09-19 11:10:00', new DateTimeZone('UTC')),
            AuditRequestId::fromString('req-ai-feedback'),
        );

        self::assertSame($item, $store->item);
        self::assertCount(1, $audit->events);
        self::assertSame('content.ai_moderation.feedback.record', $audit->events[0]->action->value());
        self::assertSame('req-ai-feedback', $audit->events[0]->requestId->value());
        self::assertStringNotContainsString(
            'Sensitive moderator note',
            json_encode($audit->events[0]->after, JSON_THROW_ON_ERROR),
        );
    }

    public function testFeedbackPermissionDenialPreventsStorageAndAudit(): void
    {
        $actor = EntityId::fromString(str_repeat('1', 32));
        $store = new FeedbackMemoryStore();
        $audit = new FeedbackRecordingAudit();
        $service = new AiModerationFeedbackService($store, $this->gate($actor, false), $audit);

        $this->expectException(\Forwext\Core\Domain\Access\Permission\PermissionDeniedException::class);
        try {
            $service->report(
                EntityId::fromString(str_repeat('2', 32)),
                'false_negative',
                'Denied feedback.',
                new DateTimeImmutable('2026-09-19 11:10:00', new DateTimeZone('UTC')),
            );
        } finally {
            self::assertNull($store->item);
            self::assertSame([], $audit->events);
        }
    }

    private function gate(EntityId $actor, bool $allowed): PermissionGate
    {
        return new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new FeedbackPermissionRules($actor, $allowed)),
                new FeedbackAssignments($actor),
            ),
            $actor,
        );
    }
}

final class FeedbackMemoryStore implements AiModerationFeedbackStore
{
    public ?AiModerationFeedback $item = null;

    public function record(AiModerationFeedback $feedback): void
    {
        $this->item = $feedback;
    }
}

final class FeedbackRecordingAudit implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function append(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    public function mutate(AuditEvent $event, callable $mutation): mixed
    {
        $result = $mutation();
        $this->events[] = $event;
        return $result;
    }
}

final readonly class FeedbackPermissionRules implements PermissionRuleRepository
{
    public function __construct(private EntityId $actor, private bool $allowed)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return $key->value() === 'ai.manage'
            ? new PermissionDefinition($key, PermissionValueType::Flag)
            : null;
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (!$this->allowed
            || $nodeId !== null
            || $key->value() !== 'ai.manage'
            || !$assignment->userId()->equals($this->actor)
        ) {
            return [];
        }
        return [new PermissionRule(PermissionSubjectType::User, $this->actor, PermissionEffect::Allow)];
    }
}

final readonly class FeedbackAssignments implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $actor)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $userId->equals($this->actor)
            ? new UserAccessAssignment($this->actor, EntityId::fromString(str_repeat('9', 32)))
            : null;
    }
}
