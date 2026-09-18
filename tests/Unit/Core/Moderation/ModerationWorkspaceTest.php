<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Moderation;

use DateTimeImmutable;
use Forwext\App\Web\Moderation\ModerationRequestGuard;
use Forwext\App\Web\Moderation\ModerationWorkspaceHtml;
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
use Forwext\Core\Http\Canonical\CanonicalUrl;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Core\Moderation\Task\ModerationTask;
use Forwext\Core\Moderation\Task\ModerationTaskPriority;
use Forwext\Core\Moderation\Task\ModerationTaskStatus;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceItem;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceSection;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceService;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceSnapshot;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceSource;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class ModerationWorkspaceTest extends TestCase
{
    public function testWorkspaceFailsClosedBeforeReadingSources(): void
    {
        $source = new RecordingModerationSource();
        $service = new ModerationWorkspaceService(self::gate(false), [$source]);

        try {
            $service->snapshot();
            self::fail('Workspace access without moderation.access must be denied.');
        } catch (\Forwext\Core\Domain\Access\Permission\PermissionDeniedException) {
            self::assertSame(0, $source->calls);
        }
    }

    public function testWorkspaceAlwaysContainsAllSectionsAndAggregatesRealSources(): void
    {
        $source = new RecordingModerationSource([
            new ModerationWorkspaceItem(
                ModerationWorkspaceSection::Approval,
                'forum.thread',
                'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'Pending topic',
                'pending',
                new DateTimeImmutable('2026-09-18T08:00:00+00:00'),
            ),
        ]);
        $snapshot = (new ModerationWorkspaceService(self::gate(true), [$source]))->snapshot(10);

        self::assertSame(1, $snapshot->count(ModerationWorkspaceSection::Approval));
        self::assertSame(0, $snapshot->count(ModerationWorkspaceSection::Reports));
        self::assertSame(0, $snapshot->count(ModerationWorkspaceSection::Warnings));
        self::assertSame(0, $snapshot->count(ModerationWorkspaceSection::Bans));
        self::assertSame(0, $snapshot->count(ModerationWorkspaceSection::Abuse));
        self::assertSame(0, $snapshot->count(ModerationWorkspaceSection::Tasks));
        self::assertSame('Pending topic', $snapshot->itemsFor(ModerationWorkspaceSection::Approval)[0]->title);
    }

    public function testWorkspaceHtmlEscapesUntrustedTitlesAndLoadsSameOriginMutationScript(): void
    {
        $counts = [];
        $items = [];
        foreach (ModerationWorkspaceSection::cases() as $section) {
            $counts[$section->value] = 0;
            $items[$section->value] = [];
        }
        $counts['tasks'] = 1;
        $items['tasks'][] = new ModerationWorkspaceItem(
            ModerationWorkspaceSection::Tasks,
            'moderation.task',
            'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            '<script>alert(1)</script>',
            'open',
            new DateTimeImmutable('2026-09-18T09:00:00+00:00'),
        );

        $html = ModerationWorkspaceHtml::page(new ModerationWorkspaceSnapshot($counts, $items), new BasePath(), true);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('/assets/moderation-workspace.js', $html);
    }

    public function testMutationGuardRequiresCustomHeaderAndRejectsForeignOrigin(): void
    {
        $guard = new ModerationRequestGuard(new CanonicalUrl('https://forum.example.test/community'));
        $allowed = new Request(
            HttpMethod::Post,
            '/community/moderation/tasks',
            new HeaderBag([
                'X-Forwext-Moderation' => '1',
                'Origin' => 'https://forum.example.test',
                'Sec-Fetch-Site' => 'same-origin',
            ]),
        );
        $foreign = new Request(
            HttpMethod::Post,
            '/community/moderation/tasks',
            new HeaderBag([
                'X-Forwext-Moderation' => '1',
                'Origin' => 'https://evil.example',
                'Sec-Fetch-Site' => 'cross-site',
            ]),
        );

        self::assertTrue($guard->allows($allowed));
        self::assertFalse($guard->allows($foreign));
    }

    public function testTaskModelAndMigrationContractAreRegistered(): void
    {
        $task = new ModerationTask(
            EntityId::fromString('cccccccccccccccccccccccccccccccc'),
            'Review queue',
            '',
            ModerationTaskPriority::High,
            ModerationTaskStatus::Open,
            EntityId::fromString('dddddddddddddddddddddddddddddddd'),
            null,
            null,
            new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
        );
        self::assertSame('high', $task->priority->value);

        $migrationIds = array_map(
            static fn ($migration): string => $migration->id()->value(),
            CoreMigrationRegistry::all(),
        );
        self::assertContains('20260918001000_moderation_workspace_tasks', $migrationIds);
    }

    private static function gate(bool $allow): PermissionGate
    {
        $userId = EntityId::fromString('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $assignment = new UserAccessAssignment($userId, EntityId::fromString('group:moderator'));
        return new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new ModerationPermissionRepository($allow)),
                new ModerationAssignmentProvider($assignment),
            ),
            $userId,
        );
    }
}

final class RecordingModerationSource implements ModerationWorkspaceSource
{
    public int $calls = 0;

    /** @param list<ModerationWorkspaceItem> $items */
    public function __construct(private array $items = [])
    {
    }

    public function section(): ModerationWorkspaceSection
    {
        return ModerationWorkspaceSection::Approval;
    }

    public function count(): int
    {
        ++$this->calls;
        return count($this->items);
    }

    public function latest(int $limit): array
    {
        ++$this->calls;
        return array_slice($this->items, 0, $limit);
    }
}

final readonly class ModerationAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment;
    }
}

final readonly class ModerationPermissionRepository implements PermissionRuleRepository
{
    public function __construct(private bool $allow)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (!$this->allow) {
            return [];
        }
        return [new PermissionRule(
            PermissionSubjectType::Group,
            $assignment->primaryGroupId(),
            PermissionEffect::Allow,
        )];
    }
}
