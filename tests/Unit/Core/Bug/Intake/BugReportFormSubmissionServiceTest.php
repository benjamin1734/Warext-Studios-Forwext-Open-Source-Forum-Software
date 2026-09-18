<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Bug\Intake;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthenticationFingerprint;
use Forwext\Core\Bug\Diagnostic\BugBrowserDeviceClassifier;
use Forwext\Core\Bug\Diagnostic\BugDiagnosticContext;
use Forwext\Core\Bug\Diagnostic\BugDiagnosticContextCollector;
use Forwext\Core\Bug\Diagnostic\BugDiagnosticContextRepository;
use Forwext\Core\Bug\Diagnostic\BugReportSubmissionService;
use Forwext\Core\Bug\Intake\BugAttachmentDownloadService;
use Forwext\Core\Bug\Intake\BugAttachmentRecord;
use Forwext\Core\Bug\Intake\BugReportDetails;
use Forwext\Core\Bug\Intake\BugReportFormSubmissionService;
use Forwext\Core\Bug\Intake\BugReportIntakeRepository;
use Forwext\Core\Bug\Intake\BugUpload;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportCategory;
use Forwext\Core\Bug\Report\BugReportHistoryEntry;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
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
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Forum\Attachment\ImageMetadataSanitizer;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Routing\Router;
use Forwext\Core\Security\Secret\SecretStore;
use Forwext\Core\Storage\ReadableStream;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Forwext\Core\Storage\StoredObject;
use LogicException;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class BugReportFormSubmissionServiceTest extends TestCase
{
    public function testFormSubmissionPersistsDetailsDiagnosticsAndPrivateAttachmentAtomically(): void
    {
        $database = new FormTransactionDatabase();
        $actor = EntityId::fromString(str_repeat('1', 32));
        $reportRepository = new FormBugReportRepository($database);
        $reportRepository->category = new BugReportCategory(
            'general',
            'General',
            '',
            BugReportSeverity::Medium,
            10,
            true,
        );

        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new FormPermissionRepository($actor, [
                'bug.report.create',
                'bug.report.view_own',
            ])),
            new FormAssignmentProvider($actor),
        );
        $reportService = new BugReportService(
            $database,
            $reportRepository,
            new PermissionGate($authorizer, $actor),
            $authorizer,
        );

        $diagnostics = new FormDiagnosticRepository($database);
        $intake = new FormIntakeRepository($database);
        $storage = new FormStorageDriver();

        $collector = new BugDiagnosticContextCollector(new BugBrowserDeviceClassifier(
            new AuthenticationFingerprint(new FormSecretStore([
                'authentication.fingerprint_key'=>str_repeat('k', 64),
            ])),
        ));
        $submission = new BugReportFormSubmissionService(
            $database,
            new BugReportSubmissionService(
                $database,
                $reportService,
                $collector,
                $diagnostics,
            ),
            $intake,
            $storage,
            new AttachmentInspector(new ImageMetadataSanitizer(), new AttachmentQuotaPolicy()),
        );

        $request = (new Request(
            HttpMethod::Post,
            '/bugs/new?csrf=secret',
            new HeaderBag(['User-Agent'=>'Mozilla/5.0 Firefox/145.0']),
        ))
            ->withAttribute(Router::ATTRIBUTE_ROUTE_NAME, 'bug.report.new')
            ->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'req-bug-form-1234');

        $receipt = $submission->submit(
            $request,
            'general',
            'Reply button is broken',
            'Submitting a reply returns an error.',
            "1. Open a thread\n2. Write a reply\n3. Submit",
            'The reply should be saved.',
            'The page shows an error.',
            '/threads/' . str_repeat('a', 32) . '?token=secret#reply',
            [new BugUpload('evidence.txt', 'plain diagnostic evidence')],
            $this->time('2026-09-18 20:30:00.000000'),
        );

        self::assertTrue($reportRepository->createdInsideTransaction);
        self::assertTrue($diagnostics->savedInsideTransaction);
        self::assertTrue($intake->detailsSavedInsideTransaction);
        self::assertTrue($intake->attachmentSavedInsideTransaction);
        self::assertSame('/threads/' . str_repeat('a', 32), $receipt->details->sourcePath);
        self::assertSame('/bugs/new', $receipt->diagnosticContext->urlPath);
        self::assertCount(1, $receipt->attachments);
        self::assertStringStartsWith('bugs/reports/', $receipt->attachments[0]->storagePath);
        self::assertSame(StorageVisibility::Private, $storage->lastVisibility);

        $download = (new BugAttachmentDownloadService(
            $reportService,
            $intake,
            $storage,
        ))->download(
            $receipt->report->reportId,
            $receipt->attachments[0]->attachmentId,
        );

        self::assertSame('plain diagnostic evidence', $download->contents);
        self::assertSame('text/plain', $download->mediaType);
        self::assertSame('evidence.txt', $download->filename);
    }

    public function testRejectsMoreThanFiveAttachmentsBeforeCreatingReport(): void
    {
        $database = new FormTransactionDatabase();
        $actor = EntityId::fromString(str_repeat('2', 32));
        $reportRepository = new FormBugReportRepository($database);
        $reportRepository->category = new BugReportCategory(
            'general',
            'General',
            '',
            BugReportSeverity::Low,
            10,
            true,
        );
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new FormPermissionRepository($actor, [
                'bug.report.create',
                'bug.report.view_own',
            ])),
            new FormAssignmentProvider($actor),
        );
        $reportService = new BugReportService(
            $database,
            $reportRepository,
            new PermissionGate($authorizer, $actor),
            $authorizer,
        );

        $service = new BugReportFormSubmissionService(
            $database,
            new BugReportSubmissionService(
                $database,
                $reportService,
                new BugDiagnosticContextCollector(new BugBrowserDeviceClassifier(
                    new AuthenticationFingerprint(new FormSecretStore([
                        'authentication.fingerprint_key'=>str_repeat('m', 64),
                    ])),
                )),
                new FormDiagnosticRepository($database),
            ),
            new FormIntakeRepository($database),
            new FormStorageDriver(),
            new AttachmentInspector(new ImageMetadataSanitizer(), new AttachmentQuotaPolicy()),
        );

        $this->expectException(\InvalidArgumentException::class);
        try {
            $service->submit(
                new Request(HttpMethod::Post, '/bugs/new'),
                'general',
                'Too many files',
                'Attachment validation test.',
                'Repeat the action.',
                'Success.',
                'Failure.',
                null,
                array_fill(0, 6, new BugUpload('evidence.txt', 'text')),
            );
        } finally {
            self::assertSame([], $reportRepository->reports);
        }
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class FormTransactionDatabase implements TransactionalQueryExecutor
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

final class FormBugReportRepository implements BugReportRepository
{
    public ?BugReportCategory $category = null;
    public bool $createdInsideTransaction = false;
    /** @var array<string,BugReport> */
    public array $reports = [];
    /** @var list<BugReportHistoryEntry> */
    public array $historyEntries = [];

    public function __construct(private FormTransactionDatabase $database)
    {
    }

    public function activeCategories(): array { return $this->category === null ? [] : [$this->category]; }
    public function category(string $key): ?BugReportCategory { return $this->category?->key === $key ? $this->category : null; }
    public function saveCategory(BugReportCategory $category): void { $this->category = $category; }

    public function create(BugReport $report): void
    {
        $this->createdInsideTransaction = $this->database->inTransaction();
        $this->reports[$report->reportId->value()] = $report;
    }

    public function find(EntityId $reportId): ?BugReport
    {
        return $this->reports[$reportId->value()] ?? null;
    }

    public function forReporter(EntityId $reporterUserId, int $limit = 50): array
    {
        return array_values(array_filter(
            $this->reports,
            static fn (BugReport $report): bool => $report->reporterUserId?->equals($reporterUserId) ?? false,
        ));
    }

    public function assign(EntityId $reportId, ?EntityId $assignedUserId, int $expectedVersion, DateTimeImmutable $now): BugReport
    {
        throw new LogicException('Unused.');
    }

    public function changeStatus(
        EntityId $reportId,
        BugReportStatus $status,
        ?DateTimeImmutable $finalizedAt,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport {
        throw new LogicException('Unused.');
    }

    public function changeSeverity(
        EntityId $reportId,
        BugReportSeverity $severity,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport {
        throw new LogicException('Unused.');
    }

    public function changeCategory(
        EntityId $reportId,
        string $categoryKey,
        int $expectedVersion,
        DateTimeImmutable $now,
    ): BugReport {
        throw new LogicException('Unused.');
    }

    public function appendHistory(BugReportHistoryEntry $entry): void
    {
        $this->historyEntries[] = $entry;
    }

    public function history(EntityId $reportId, bool $includeStaff, int $limit = 200): array
    {
        return array_values(array_filter(
            $this->historyEntries,
            static fn (BugReportHistoryEntry $entry): bool => $entry->reportId->equals($reportId),
        ));
    }
}

final class FormDiagnosticRepository implements BugDiagnosticContextRepository
{
    public bool $savedInsideTransaction = false;
    public ?BugDiagnosticContext $context = null;

    public function __construct(private FormTransactionDatabase $database)
    {
    }

    public function save(BugDiagnosticContext $context): void
    {
        $this->savedInsideTransaction = $this->database->inTransaction();
        $this->context = $context;
    }

    public function find(EntityId $reportId): ?BugDiagnosticContext
    {
        return $this->context?->reportId->equals($reportId) ? $this->context : null;
    }
}

final class FormIntakeRepository implements BugReportIntakeRepository
{
    public bool $detailsSavedInsideTransaction = false;
    public bool $attachmentSavedInsideTransaction = false;
    public ?BugReportDetails $storedDetails = null;
    /** @var list<BugAttachmentRecord> */
    public array $storedAttachments = [];

    public function __construct(private FormTransactionDatabase $database)
    {
    }

    public function saveDetails(BugReportDetails $details): void
    {
        $this->detailsSavedInsideTransaction = $this->database->inTransaction();
        $this->storedDetails = $details;
    }

    public function details(EntityId $reportId): ?BugReportDetails
    {
        return $this->storedDetails?->reportId->equals($reportId) ? $this->storedDetails : null;
    }

    public function saveAttachment(BugAttachmentRecord $attachment): void
    {
        $this->attachmentSavedInsideTransaction = $this->database->inTransaction();
        $this->storedAttachments[] = $attachment;
    }

    public function attachments(EntityId $reportId): array
    {
        return array_values(array_filter(
            $this->storedAttachments,
            static fn (BugAttachmentRecord $record): bool => $record->reportId->equals($reportId),
        ));
    }
}

final class FormStorageDriver implements StorageDriver
{
    /** @var array<string,array{object:StoredObject,contents:string}> */
    private array $objects = [];
    public ?StorageVisibility $lastVisibility = null;

    public function put(
        StoragePath $path,
        string $contents,
        StorageVisibility $visibility = StorageVisibility::Private,
        ?string $contentType = null,
    ): StoredObject {
        $object = new StoredObject(
            $path,
            $visibility,
            strlen($contents),
            hash('sha256', $contents),
            $contentType,
        );
        $this->objects[$this->key($path, $visibility)] = ['object'=>$object,'contents'=>$contents];
        $this->lastVisibility = $visibility;
        return $object;
    }

    public function putStream(
        StoragePath $path,
        ReadableStream $stream,
        StorageVisibility $visibility = StorageVisibility::Private,
        ?string $contentType = null,
    ): StoredObject {
        return $this->put($path, $stream->contents(), $visibility, $contentType);
    }

    public function read(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): string {
        return $this->objects[$this->key($path, $visibility)]['contents']
            ?? throw new LogicException('Stored object missing.');
    }

    public function readStream(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): ReadableStream {
        return ReadableStream::fromString($this->read($path, $visibility));
    }

    public function metadata(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): ?StoredObject {
        return $this->objects[$this->key($path, $visibility)]['object'] ?? null;
    }

    public function exists(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): bool {
        return isset($this->objects[$this->key($path, $visibility)]);
    }

    public function delete(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): bool {
        $key = $this->key($path, $visibility);
        if (!isset($this->objects[$key])) {
            return false;
        }
        unset($this->objects[$key]);
        return true;
    }

    public function publicUrl(StoragePath $path): ?string
    {
        return null;
    }

    private function key(StoragePath $path, StorageVisibility $visibility): string
    {
        return $visibility->value . ':' . $path->value();
    }
}

final readonly class FormAssignmentProvider implements UserAccessAssignmentProvider
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

final readonly class FormPermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $permissions */
    public function __construct(private EntityId $actor, private array $permissions)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(
        PermissionKey $key,
        UserAccessAssignment $assignment,
        ?EntityId $nodeId,
    ): array {
        if (
            $nodeId !== null
            || !$assignment->userId()->equals($this->actor)
            || !in_array($key->value(), $this->permissions, true)
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

final class FormSecretStore implements SecretStore
{
    /** @param array<string,string> $secrets */
    public function __construct(private array $secrets)
    {
    }

    public function has(string $name): bool { return array_key_exists($name, $this->secrets); }
    public function get(string $name): ?string { return $this->secrets[$name] ?? null; }
    public function set(string $name, #[SensitiveParameter] string $value): void { $this->secrets[$name] = $value; }
    public function delete(string $name): bool
    {
        if (!array_key_exists($name, $this->secrets)) return false;
        unset($this->secrets[$name]);
        return true;
    }
    public function all(): array { return $this->secrets; }
}
