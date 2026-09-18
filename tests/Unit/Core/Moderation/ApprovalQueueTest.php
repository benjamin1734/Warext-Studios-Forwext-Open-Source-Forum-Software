<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Moderation;

use DateTimeImmutable;
use Forwext\App\Web\Moderation\ApprovalQueueHtml;
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
use Forwext\Core\Forum\Moderation\BulkPostAction;
use Forwext\Core\Forum\Moderation\BulkThreadAction;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Moderation\Approval\ApprovalQueueAction;
use Forwext\Core\Moderation\Approval\ApprovalQueueItem;
use Forwext\Core\Moderation\Approval\ApprovalQueueProvider;
use Forwext\Core\Moderation\Approval\ApprovalQueueRegistry;
use Forwext\Core\Moderation\Approval\ApprovalQueueSelection;
use Forwext\Core\Moderation\Approval\ApprovalQueueService;
use Forwext\Core\Moderation\Approval\ApprovalQueueSnapshot;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ApprovalQueueTest extends TestCase
{
    public function testSelectionTokenRoundTripsWithoutTrustingClientTypeOwnership(): void
    {
        $selection = new ApprovalQueueSelection('forum.thread', $this->id('a'));
        $parsed = ApprovalQueueSelection::fromToken($selection->token());

        self::assertSame('forum.thread', $parsed->sourceType);
        self::assertSame($this->id('a')->value(), $parsed->sourceId->value());
    }

    public function testRegistryRejectsDuplicateSourceTypeOwnership(): void
    {
        $registry = new ApprovalQueueRegistry([new RecordingApprovalProvider(['forum.thread'])]);

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new RecordingApprovalProvider(['forum.thread']));
    }

    public function testServiceFailsClosedBeforeReadingProvidersWithoutModerationAccess(): void
    {
        $provider = new RecordingApprovalProvider(['forum.thread']);
        $service = new ApprovalQueueService(new ApprovalQueueRegistry([$provider]), $this->gate([]));

        try {
            $service->snapshot();
            self::fail('Approval queue must require moderation.access.');
        } catch (\Forwext\Core\Domain\Access\Permission\PermissionDeniedException) {
            self::assertSame(0, $provider->readCalls);
        }
    }

    public function testManagePermissionIsRequiredBeforeProviderMutation(): void
    {
        $provider = new RecordingApprovalProvider(['forum.thread']);
        $service = new ApprovalQueueService(
            new ApprovalQueueRegistry([$provider]),
            $this->gate(['moderation.access']),
        );

        try {
            $service->moderate(
                ApprovalQueueAction::Approve,
                [new ApprovalQueueSelection('forum.thread', $this->id('b'))],
                ModerationReasonCode::fromString('approval.manual'),
                ModerationRequestId::fromString('approval-test-request'),
                new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
            );
            self::fail('Approval mutation must require moderation.manage.');
        } catch (\Forwext\Core\Domain\Access\Permission\PermissionDeniedException) {
            self::assertSame([], $provider->moderations);
        }
    }

    public function testRegistryDeduplicatesSelectionsAndDispatchesOnlyToOwningProvider(): void
    {
        $forum = new RecordingApprovalProvider(['forum.thread', 'forum.post']);
        $profile = new RecordingApprovalProvider(['profile.post']);
        $registry = new ApprovalQueueRegistry([$forum, $profile]);
        $thread = new ApprovalQueueSelection('forum.thread', $this->id('c'));
        $profilePost = new ApprovalQueueSelection('profile.post', $this->id('d'));

        $processed = $registry->moderate(
            ApprovalQueueAction::Reject,
            [$thread, $thread, $profilePost],
            ModerationReasonCode::fromString('approval.rules'),
            ModerationRequestId::fromString('approval-dispatch-request'),
            new DateTimeImmutable('2026-09-18T10:01:00+00:00'),
        );

        self::assertSame(2, $processed);
        self::assertSame([['reject', [$thread->token()], 'approval.rules']], $forum->moderations);
        self::assertSame([['reject', [$profilePost->token()], 'approval.rules']], $profile->moderations);
    }

    public function testHtmlEscapesQueueContentAndUsesSameOriginModerationForm(): void
    {
        $snapshot = new ApprovalQueueSnapshot(1, [
            new ApprovalQueueItem(
                'forum.thread',
                $this->id('e'),
                '<script>alert(1)</script>',
                new DateTimeImmutable('2026-09-18T10:02:00+00:00'),
                '<img src=x onerror=alert(1)>',
            ),
        ]);

        $html = ApprovalQueueHtml::page($snapshot, new BasePath('/community'), true);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringContainsString('/community/moderation/approval/actions', $html);
        self::assertStringContainsString('data-moderation-form', $html);
    }

    public function testRejectIsAFirstClassBulkModerationAction(): void
    {
        self::assertSame('reject', BulkThreadAction::Reject->value);
        self::assertSame('reject', BulkPostAction::Reject->value);
    }

    /** @param list<string> $allowed */
    private function gate(array $allowed): PermissionGate
    {
        $actor = $this->id('1');
        $assignment = new UserAccessAssignment($actor, $this->id('2'));
        return new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new ApprovalQueuePermissionRepository($actor, $allowed)),
                new ApprovalQueueAssignmentProvider($assignment),
            ),
            $actor,
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}

final class RecordingApprovalProvider implements ApprovalQueueProvider
{
    public int $readCalls = 0;

    /** @var list<array{0:string,1:list<string>,2:string}> */
    public array $moderations = [];

    /** @param list<string> $types @param list<ApprovalQueueItem> $items */
    public function __construct(private array $types, private array $items = [])
    {
    }

    public function sourceTypes(): array
    {
        return $this->types;
    }

    public function count(): int
    {
        ++$this->readCalls;
        return count($this->items);
    }

    public function latest(int $limit): array
    {
        ++$this->readCalls;
        return array_slice($this->items, 0, $limit);
    }

    public function moderate(
        ApprovalQueueAction $action,
        array $selections,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->moderations[] = [
            $action->value,
            array_map(static fn (ApprovalQueueSelection $selection): string => $selection->token(), $selections),
            $reason->value(),
        ];
    }
}

final readonly class ApprovalQueueAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final readonly class ApprovalQueuePermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $allowed */
    public function __construct(private EntityId $actor, private array $allowed)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (
            !$assignment->userId()->equals($this->actor)
            || $nodeId !== null
            || !in_array($key->value(), $this->allowed, true)
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
