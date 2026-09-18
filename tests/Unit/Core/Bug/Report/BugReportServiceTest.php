<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Bug\Report;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Bug\Report\BugHistoryEventType;
use Forwext\Core\Bug\Report\BugHistoryVisibility;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportCategory;
use Forwext\Core\Bug\Report\BugReportHistoryEntry;
use Forwext\Core\Bug\Report\BugReportNotifier;
use Forwext\Core\Bug\Report\BugReportOperationException;
use Forwext\Core\Bug\Report\NullBugReportNotifier;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
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
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BugReportServiceTest extends TestCase
{
    public function testCreateUsesCategoryDefaultSeverityAndAppendsPublicCreatedHistory(): void
    {
        $actor = $this->id('1');
        $repo = new BugMemoryRepository();
        $repo->categories['general'] = $this->category();
        $service = $this->service($actor, [
            $actor->value()=>['bug.report.create','bug.report.view_own'],
        ], $repo);
        $now = $this->time('2026-09-18 18:00:00.000000');

        $report = $service->create('GENERAL', ' Broken save ', ' Saving fails every time. ', null, $now);

        self::assertSame('general', $report->categoryKey);
        self::assertSame('Broken save', $report->title);
        self::assertSame('Saving fails every time.', $report->summary);
        self::assertSame(BugReportSeverity::Medium, $report->severity);
        self::assertSame(BugReportStatus::New, $report->status);
        self::assertSame($actor->value(), $report->reporterUserId?->value());
        self::assertCount(1, $repo->historyEntries);
        self::assertSame(BugHistoryEventType::Created, $repo->historyEntries[0]->eventType);
        self::assertSame(BugHistoryVisibility::Public, $repo->historyEntries[0]->visibility);
    }

    public function testReporterCannotReadAnotherUsersBugReportById(): void
    {
        $actor = $this->id('1');
        $repo = new BugMemoryRepository();
        $report = $this->report($this->id('2'));
        $repo->reports[$report->reportId->value()] = $report;
        $service = $this->service($actor, [
            $actor->value()=>['bug.report.view_own'],
        ], $repo);

        $this->expectException(PermissionDeniedException::class);
        $service->report($report->reportId);
    }

    public function testAssignmentRejectsTargetWithoutBugStaffAccess(): void
    {
        $staff = $this->id('1');
        $assignee = $this->id('3');
        $repo = new BugMemoryRepository();
        $report = $this->report($this->id('2'));
        $repo->reports[$report->reportId->value()] = $report;
        $service = $this->service($staff, [
            $staff->value()=>['bug.report.view_all','bug.report.assign'],
            $assignee->value()=>[],
        ], $repo);

        $this->expectException(InvalidArgumentException::class);
        $service->assign($report->reportId, $assignee);
    }

    public function testTerminalStatusSetsFinalizedTimestampAndReopenClearsIt(): void
    {
        $staff = $this->id('1');
        $repo = new BugMemoryRepository();
        $report = $this->report($this->id('2'));
        $repo->reports[$report->reportId->value()] = $report;
        $service = $this->service($staff, [
            $staff->value()=>['bug.report.view_all','bug.report.manage'],
        ], $repo);

        $resolvedAt = $this->time('2026-09-18 18:30:00.000000');
        $resolved = $service->changeStatus($report->reportId, BugReportStatus::Resolved, $resolvedAt);

        self::assertSame(BugReportStatus::Resolved, $resolved->status);
        self::assertSame($resolvedAt->format('c'), $resolved->finalizedAt?->format('c'));

        $reopened = $service->changeStatus(
            $report->reportId,
            BugReportStatus::New,
            $this->time('2026-09-18 19:00:00.000000'),
        );

        self::assertSame(BugReportStatus::New, $reopened->status);
        self::assertNull($reopened->finalizedAt);
        self::assertSame(3, $reopened->version);
        self::assertCount(2, $repo->historyEntries);
    }

    public function testAssignmentHistoryIsStaffOnly(): void
    {
        $staff = $this->id('1');
        $reporter = $this->id('2');
        $assignee = $this->id('3');
        $repo = new BugMemoryRepository();
        $report = $this->report($reporter);
        $repo->reports[$report->reportId->value()] = $report;

        $staffService = $this->service($staff, [
            $staff->value()=>['bug.report.view_all','bug.report.assign'],
            $assignee->value()=>['bug.report.view_all'],
            $reporter->value()=>['bug.report.view_own'],
        ], $repo);
        $staffService->assign(
            $report->reportId,
            $assignee,
            $this->time('2026-09-18 18:15:00.000000'),
        );

        $reporterService = $this->service($reporter, [
            $reporter->value()=>['bug.report.view_own'],
        ], $repo);

        self::assertCount(0, $reporterService->history($report->reportId));
        self::assertCount(1, $staffService->history($report->reportId));
        self::assertSame(BugHistoryVisibility::Staff, $repo->historyEntries[0]->visibility);
    }

    public function testSeverityAndCategoryChangesAreTrackedAndInactiveCategoryIsRejected(): void
    {
        $staff = $this->id('1');
        $repo = new BugMemoryRepository();
        $repo->categories['general'] = $this->category();
        $repo->categories['security'] = new BugReportCategory(
            'security',
            'Security',
            '',
            BugReportSeverity::High,
            20,
            true,
        );
        $repo->categories['disabled'] = new BugReportCategory(
            'disabled',
            'Disabled',
            '',
            BugReportSeverity::Low,
            30,
            false,
        );
        $report = $this->report($this->id('2'));
        $repo->reports[$report->reportId->value()] = $report;
        $service = $this->service($staff, [
            $staff->value()=>['bug.report.view_all','bug.report.manage'],
        ], $repo);

        $service->changeSeverity($report->reportId, BugReportSeverity::Critical);
        $changed = $service->changeCategory($report->reportId, 'security');

        self::assertSame('security', $changed->categoryKey);
        self::assertSame(BugReportSeverity::Critical, $changed->severity);
        self::assertCount(2, $repo->historyEntries);

        $this->expectException(InvalidArgumentException::class);
        $service->changeCategory($report->reportId, 'disabled');
    }

    public function testReporterAdditionalInfoIsPublicTrackedAndNotified(): void
    {
        $reporter = $this->id('2');
        $repo = new BugMemoryRepository();
        $report = $this->report($reporter);
        $repo->reports[$report->reportId->value()] = $report;
        $notifier = new RecordingBugReportNotifier();
        $service = $this->service($reporter, [
            $reporter->value()=>['bug.report.view_own'],
        ], $repo, $notifier);

        $entry = $service->addReporterInfo(
            $report->reportId,
            '  New reproduction detail.  ',
            $this->time('2026-09-18 19:20:00.000000'),
        );

        self::assertSame(BugHistoryEventType::ReporterInfoAdded, $entry->eventType);
        self::assertSame(BugHistoryVisibility::Public, $entry->visibility);
        self::assertSame('New reproduction detail.', $entry->payload['body']);
        self::assertSame(1, $notifier->reporterInfoCount);
        self::assertCount(1, $service->history($report->reportId));
    }

    public function testStaffResponseIsPublicAndReporterNotificationHookRuns(): void
    {
        $staff = $this->id('1');
        $reporter = $this->id('2');
        $repo = new BugMemoryRepository();
        $report = $this->report($reporter);
        $repo->reports[$report->reportId->value()] = $report;
        $notifier = new RecordingBugReportNotifier();
        $service = $this->service($staff, [
            $staff->value()=>['bug.report.view_all','bug.report.manage'],
            $reporter->value()=>['bug.report.view_own'],
        ], $repo, $notifier);

        $entry = $service->staffRespond(
            $report->reportId,
            'Please test the fix.',
            $this->time('2026-09-18 19:25:00.000000'),
        );

        self::assertSame(BugHistoryEventType::StaffResponse, $entry->eventType);
        self::assertSame('Please test the fix.', $entry->payload['body']);
        self::assertSame(1, $notifier->staffResponseCount);
    }

    public function testTerminalReportRejectsReporterAdditionalInfo(): void
    {
        $reporter = $this->id('2');
        $repo = new BugMemoryRepository();
        $report = $this->report($reporter);
        $repo->reports[$report->reportId->value()] = $report;
        $staff = $this->id('1');
        $staffService = $this->service($staff, [
            $staff->value()=>['bug.report.view_all','bug.report.manage'],
        ], $repo);
        $staffService->changeStatus($report->reportId, BugReportStatus::Resolved);

        $reporterService = $this->service($reporter, [
            $reporter->value()=>['bug.report.view_own'],
        ], $repo);

        $this->expectException(BugReportOperationException::class);
        $reporterService->addReporterInfo($report->reportId, 'Late detail');
    }

    public function testDuplicateIsTerminalAndCanOnlyReopenToNew(): void
    {
        self::assertTrue(BugReportStatus::Duplicate->isTerminal());
        self::assertTrue(BugReportStatus::Duplicate->canTransitionTo(BugReportStatus::New));
        self::assertFalse(BugReportStatus::Duplicate->canTransitionTo(BugReportStatus::InReview));
    }

    /**
     * @param array<string,list<string>> $permissions
     */
    private function service(
        EntityId $actor,
        array $permissions,
        BugMemoryRepository $repo,
        ?BugReportNotifier $notifier = null,
    ): BugReportService
    {
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new BugPermissionRepository($permissions)),
            new BugAssignmentProvider(array_keys($permissions)),
        );

        return new BugReportService(
            new BugTransactionDatabase(),
            $repo,
            new PermissionGate($authorizer, $actor),
            $authorizer,
            $notifier ?? new NullBugReportNotifier(),
        );
    }

    private function category(): BugReportCategory
    {
        return new BugReportCategory(
            'general',
            'General',
            '',
            BugReportSeverity::Medium,
            10,
            true,
        );
    }

    private function report(EntityId $reporter): BugReport
    {
        $at = $this->time('2026-09-18 18:00:00.000000');
        return new BugReport(
            $this->id('a'),
            'general',
            $reporter,
            null,
            'Stored bug',
            'Stored bug summary.',
            BugReportSeverity::Medium,
            BugReportStatus::New,
            null,
            $at,
            $at,
            1,
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

final class BugMemoryRepository implements BugReportRepository
{
    /** @var array<string,BugReportCategory> */
    public array $categories = [];
    /** @var array<string,BugReport> */
    public array $reports = [];
    /** @var list<BugReportHistoryEntry> */
    public array $historyEntries = [];

    public function activeCategories(): array
    {
        return array_values(array_filter($this->categories, static fn (BugReportCategory $c): bool => $c->active));
    }

    public function category(string $key): ?BugReportCategory
    {
        return $this->categories[$key] ?? null;
    }

    public function saveCategory(BugReportCategory $category): void
    {
        $this->categories[$category->key] = $category;
    }

    public function create(BugReport $report): void
    {
        $this->reports[$report->reportId->value()] = $report;
    }

    public function find(EntityId $reportId): ?BugReport
    {
        return $this->reports[$reportId->value()] ?? null;
    }

    public function forReporter(EntityId $reporterUserId, int $limit = 50): array
    {
        return array_slice(array_values(array_filter(
            $this->reports,
            static fn (BugReport $report): bool => $report->reporterUserId?->equals($reporterUserId) ?? false,
        )), 0, $limit);
    }

    public function assign(EntityId $reportId, ?EntityId $assignedUserId, int $expectedVersion, DateTimeImmutable $now): BugReport
    {
        $current = $this->versioned($reportId, $expectedVersion);
        return $this->store(new BugReport(
            $current->reportId,
            $current->categoryKey,
            $current->reporterUserId,
            $assignedUserId,
            $current->title,
            $current->summary,
            $current->severity,
            $current->status,
            $current->finalizedAt,
            $current->createdAt,
            $now,
            $current->version + 1,
        ));
    }

    public function changeStatus(EntityId $reportId, BugReportStatus $status, ?DateTimeImmutable $finalizedAt, int $expectedVersion, DateTimeImmutable $now): BugReport
    {
        $current = $this->versioned($reportId, $expectedVersion);
        return $this->store(new BugReport(
            $current->reportId,
            $current->categoryKey,
            $current->reporterUserId,
            $current->assignedUserId,
            $current->title,
            $current->summary,
            $current->severity,
            $status,
            $finalizedAt,
            $current->createdAt,
            $now,
            $current->version + 1,
        ));
    }

    public function changeSeverity(EntityId $reportId, BugReportSeverity $severity, int $expectedVersion, DateTimeImmutable $now): BugReport
    {
        $current = $this->versioned($reportId, $expectedVersion);
        return $this->store(new BugReport(
            $current->reportId,
            $current->categoryKey,
            $current->reporterUserId,
            $current->assignedUserId,
            $current->title,
            $current->summary,
            $severity,
            $current->status,
            $current->finalizedAt,
            $current->createdAt,
            $now,
            $current->version + 1,
        ));
    }

    public function changeCategory(EntityId $reportId, string $categoryKey, int $expectedVersion, DateTimeImmutable $now): BugReport
    {
        $current = $this->versioned($reportId, $expectedVersion);
        return $this->store(new BugReport(
            $current->reportId,
            $categoryKey,
            $current->reporterUserId,
            $current->assignedUserId,
            $current->title,
            $current->summary,
            $current->severity,
            $current->status,
            $current->finalizedAt,
            $current->createdAt,
            $now,
            $current->version + 1,
        ));
    }

    public function appendHistory(BugReportHistoryEntry $entry): void
    {
        $this->historyEntries[] = $entry;
    }

    public function history(EntityId $reportId, bool $includeStaff, int $limit = 200): array
    {
        return array_slice(array_values(array_filter(
            $this->historyEntries,
            static fn (BugReportHistoryEntry $entry): bool => $entry->reportId->equals($reportId)
                && ($includeStaff || $entry->visibility === BugHistoryVisibility::Public),
        )), 0, $limit);
    }

    private function versioned(EntityId $reportId, int $expectedVersion): BugReport
    {
        $report = $this->find($reportId) ?? throw new BugReportOperationException('Bug report missing.');
        if ($report->version !== $expectedVersion) {
            throw new BugReportOperationException('Stale bug report version.');
        }
        return $report;
    }

    private function store(BugReport $report): BugReport
    {
        $this->reports[$report->reportId->value()] = $report;
        return $report;
    }
}

final class BugTransactionDatabase implements TransactionalQueryExecutor
{
    private bool $inside = false;

    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->inside; }

    public function transaction(Closure $callback): mixed
    {
        $before = $this->inside;
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = $before;
        }
    }
}

final readonly class BugAssignmentProvider implements UserAccessAssignmentProvider
{
    /** @param list<string> $userIds */
    public function __construct(private array $userIds)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return in_array($userId->value(), $this->userIds, true)
            ? new UserAccessAssignment($userId, EntityId::fromString(str_repeat('f', 32)))
            : null;
    }
}

final readonly class BugPermissionRepository implements PermissionRuleRepository
{
    /** @param array<string,list<string>> $permissions */
    public function __construct(private array $permissions)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($nodeId !== null
            || !in_array($key->value(), $this->permissions[$assignment->userId()->value()] ?? [], true)
        ) {
            return [];
        }

        return [new PermissionRule(
            PermissionSubjectType::User,
            $assignment->userId(),
            PermissionEffect::Allow,
        )];
    }
}


final class RecordingBugReportNotifier implements BugReportNotifier
{
    public int $staffResponseCount = 0;
    public int $statusCount = 0;
    public int $reporterInfoCount = 0;

    public function staffResponse(BugReport $report, BugReportHistoryEntry $entry): void
    {
        $this->staffResponseCount++;
    }

    public function statusChanged(BugReport $report): void
    {
        $this->statusCount++;
    }

    public function reporterInfoAdded(BugReport $report, BugReportHistoryEntry $entry): void
    {
        $this->reporterInfoCount++;
    }
}
