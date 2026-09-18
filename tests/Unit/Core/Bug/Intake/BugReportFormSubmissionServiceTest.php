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
use Forwext\Core\Bug\Intake\BugAttachmentRecord;
use Forwext\Core\Bug\Intake\BugReportFormSubmissionService;
use Forwext\Core\Bug\Intake\BugReportIntake;
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
    public function testSubmissionPersistsFormContextAndSafeAttachmentAtomically(): void
    {
        $database = new BugFormTransactionDatabase();
        $actor = EntityId::fromString(str_repeat('1', 32));
        $reports = new BugFormReportRepository($database);
        $reports->category = new BugReportCategory('general','General','',BugReportSeverity::Medium,10,true);
        $diagnostics = new BugFormDiagnosticRepository($database);
        $intake = new BugFormIntakeRepository($database);
        $storage = new BugFormStorage();
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new BugFormPermissionRepository($actor, ['bug.report.create','bug.report.view_own'])),
            new BugFormAssignmentProvider($actor),
        );
        $reportService = new BugReportService(
            $database,
            $reports,
            new PermissionGate($authorizer, $actor),
            $authorizer,
        );
        $collector = new BugDiagnosticContextCollector(new BugBrowserDeviceClassifier(
            new AuthenticationFingerprint(new BugFormSecretStore([
                'authentication.fingerprint_key'=>str_repeat('k',64),
            ])),
        ));
        $service = new BugReportFormSubmissionService(
            $database,
            new BugReportSubmissionService($database,$reportService,$collector,$diagnostics),
            $intake,
            $storage,
            new AttachmentInspector(new ImageMetadataSanitizer(), new AttachmentQuotaPolicy()),
        );
        $request = (new Request(
            HttpMethod::Post,
            '/bugs/report?csrf=secret',
            new HeaderBag(['User-Agent'=>'Mozilla/5.0 Firefox/145.0']),
        ))->withAttribute(Router::ATTRIBUTE_ROUTE_NAME, 'bug.report.create');

        $receipt = $service->submit(
            $request,
            'general',
            'Broken editor',
            'Editor submit fails.',
            "1. Open editor\n2. Submit",
            'Post should be created.',
            'Button returns an error.',
            '/threads/' . str_repeat('a',32) . '?secret=drop',
            [new BugUpload('evidence.txt','plain evidence')],
            null,
            $this->time('2026-09-18 20:00:00.000000'),
        );

        self::assertTrue($reports->createdInsideTransaction);
        self::assertTrue($diagnostics->savedInsideTransaction);
        self::assertTrue($intake->savedInsideTransaction);
        self::assertSame('/threads/' . str_repeat('a',32), $receipt->intake->reportedSourcePath);
        self::assertCount(1,$receipt->attachments);
        self::assertSame('text/plain',$receipt->attachments[0]->mediaType);
        self::assertSame('plain evidence',array_values($storage->objects)[0]);
        self::assertSame($receipt->submission->report->reportId->value(),$receipt->intake->reportId->value());
    }

    public function testIntakeRejectsQueryBearingSourcePathWhenConstructedDirectly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BugReportIntake(
            EntityId::fromString(str_repeat('a',32)),
            'steps',
            'expected',
            'actual',
            '/safe?token=secret',
            $this->time('2026-09-18 20:00:00.000000'),
        );
    }

    private function time(string $value): DateTimeImmutable
    {
        $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$value,new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class,$time);
        return $time;
    }
}

final class BugFormTransactionDatabase implements TransactionalQueryExecutor
{
    private bool $inside=false;
    public function execute(CompiledQuery $query): int{return 1;}
    public function fetchOne(CompiledQuery $query): ?array{return null;}
    public function fetchAll(CompiledQuery $query): array{return [];}
    public function fetchValue(CompiledQuery $query): mixed{return null;}
    public function inTransaction(): bool{return $this->inside;}
    public function transaction(Closure $callback): mixed
    {
        $before=$this->inside;$this->inside=true;
        try{return $callback($this);}finally{$this->inside=$before;}
    }
}

final class BugFormReportRepository implements BugReportRepository
{
    public ?BugReportCategory $category=null;
    public bool $createdInsideTransaction=false;
    /** @var array<string,BugReport> */
    public array $reports=[];
    /** @var list<BugReportHistoryEntry> */
    public array $history=[];

    public function __construct(private BugFormTransactionDatabase $database){}
    public function activeCategories(): array{return $this->category===null?[]:[$this->category];}
    public function category(string $key): ?BugReportCategory{return $this->category?->key===$key?$this->category:null;}
    public function saveCategory(BugReportCategory $category): void{$this->category=$category;}
    public function create(BugReport $report): void{$this->createdInsideTransaction=$this->database->inTransaction();$this->reports[$report->reportId->value()]=$report;}
    public function find(EntityId $reportId): ?BugReport{return $this->reports[$reportId->value()]??null;}
    public function forReporter(EntityId $reporterUserId,int $limit=50): array{return [];}
    public function assign(EntityId $reportId,?EntityId $assignedUserId,int $expectedVersion,DateTimeImmutable $now): BugReport{throw new LogicException();}
    public function changeStatus(EntityId $reportId,BugReportStatus $status,?DateTimeImmutable $finalizedAt,int $expectedVersion,DateTimeImmutable $now): BugReport{throw new LogicException();}
    public function changeSeverity(EntityId $reportId,BugReportSeverity $severity,int $expectedVersion,DateTimeImmutable $now): BugReport{throw new LogicException();}
    public function changeCategory(EntityId $reportId,string $categoryKey,int $expectedVersion,DateTimeImmutable $now): BugReport{throw new LogicException();}
    public function appendHistory(BugReportHistoryEntry $entry): void{$this->history[]=$entry;}
    public function history(EntityId $reportId,bool $includeStaff,int $limit=200): array{return [];}
}

final class BugFormDiagnosticRepository implements BugDiagnosticContextRepository
{
    public bool $savedInsideTransaction=false;
    public ?BugDiagnosticContext $context=null;
    public function __construct(private BugFormTransactionDatabase $database){}
    public function save(BugDiagnosticContext $context): void{$this->savedInsideTransaction=$this->database->inTransaction();$this->context=$context;}
    public function find(EntityId $reportId): ?BugDiagnosticContext{return $this->context;}
}

final class BugFormIntakeRepository implements BugReportIntakeRepository
{
    public bool $savedInsideTransaction=false;
    public ?BugReportIntake $record=null;
    /** @var list<BugAttachmentRecord> */
    public array $files=[];
    public function __construct(private BugFormTransactionDatabase $database){}
    public function saveIntake(BugReportIntake $intake): void{$this->savedInsideTransaction=$this->database->inTransaction();$this->record=$intake;}
    public function intake(EntityId $reportId): ?BugReportIntake{return $this->record;}
    public function saveAttachment(BugAttachmentRecord $attachment): void{$this->files[]=$attachment;}
    public function attachments(EntityId $reportId): array{return $this->files;}
}

final class BugFormStorage implements StorageDriver
{
    /** @var array<string,string> */
    public array $objects=[];
    public function put(StoragePath $path,string $contents,StorageVisibility $visibility=StorageVisibility::Private,?string $contentType=null): StoredObject
    {
        $this->objects[$path->value()]=$contents;
        return new StoredObject($path,$visibility,strlen($contents),hash('sha256',$contents),$contentType);
    }
    public function putStream(StoragePath $path,ReadableStream $stream,StorageVisibility $visibility=StorageVisibility::Private,?string $contentType=null): StoredObject
    {return $this->put($path,$stream->contents(),$visibility,$contentType);}
    public function read(StoragePath $path,StorageVisibility $visibility=StorageVisibility::Private): string{return $this->objects[$path->value()]??throw new \RuntimeException();}
    public function readStream(StoragePath $path,StorageVisibility $visibility=StorageVisibility::Private): ReadableStream{return ReadableStream::fromString($this->read($path,$visibility));}
    public function metadata(StoragePath $path,StorageVisibility $visibility=StorageVisibility::Private): ?StoredObject{return null;}
    public function exists(StoragePath $path,StorageVisibility $visibility=StorageVisibility::Private): bool{return isset($this->objects[$path->value()]);}
    public function delete(StoragePath $path,StorageVisibility $visibility=StorageVisibility::Private): bool
    {
        if(!isset($this->objects[$path->value()]))return false;unset($this->objects[$path->value()]);return true;
    }
    public function publicUrl(StoragePath $path): ?string{return null;}
}

final readonly class BugFormAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $actor){}
    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $userId->equals($this->actor)?new UserAccessAssignment($userId,EntityId::fromString(str_repeat('f',32))):null;
    }
}

final readonly class BugFormPermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $permissions */
    public function __construct(private EntityId $actor,private array $permissions){}
    public function definition(PermissionKey $key): ?PermissionDefinition{return new PermissionDefinition($key,PermissionValueType::Flag);}
    public function rules(PermissionKey $key,UserAccessAssignment $assignment,?EntityId $nodeId): array
    {
        if($nodeId!==null||!$assignment->userId()->equals($this->actor)||!in_array($key->value(),$this->permissions,true))return [];
        return [new PermissionRule(PermissionSubjectType::User,$this->actor,PermissionEffect::Allow)];
    }
}

final class BugFormSecretStore implements SecretStore
{
    /** @param array<string,string> $secrets */
    public function __construct(private array $secrets){}
    public function has(string $name): bool{return array_key_exists($name,$this->secrets);}
    public function get(string $name): ?string{return $this->secrets[$name]??null;}
    public function set(string $name,#[SensitiveParameter] string $value): void{$this->secrets[$name]=$value;}
    public function delete(string $name): bool{if(!isset($this->secrets[$name]))return false;unset($this->secrets[$name]);return true;}
    public function all(): array{return $this->secrets;}
}
