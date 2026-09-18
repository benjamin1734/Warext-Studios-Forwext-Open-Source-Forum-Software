<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Support\Intake;

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
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Forum\Attachment\ImageMetadataSanitizer;
use Forwext\Core\Storage\ReadableStream;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Forwext\Core\Storage\StoredObject;
use Forwext\Core\Support\Intake\AccountSupportContextResolver;
use Forwext\Core\Support\Intake\SupportAttachmentRecord;
use Forwext\Core\Support\Intake\SupportContextLink;
use Forwext\Core\Support\Intake\SupportContextRegistry;
use Forwext\Core\Support\Intake\SupportContextResolver;
use Forwext\Core\Support\Intake\SupportContextType;
use Forwext\Core\Support\Intake\SupportContextUnavailableException;
use Forwext\Core\Support\Intake\SupportFieldDefinition;
use Forwext\Core\Support\Intake\SupportFieldType;
use Forwext\Core\Support\Intake\SupportFieldValue;
use Forwext\Core\Support\Intake\SupportSubmissionRateLimitException;
use Forwext\Core\Support\Intake\SupportSubmissionRateLimiter;
use Forwext\Core\Support\Intake\SupportTicketIntakeRepository;
use Forwext\Core\Support\Intake\SupportTicketSubmissionService;
use Forwext\Core\Support\Intake\SupportUpload;
use Forwext\Core\Support\Ticket\SupportCategory;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Support\Ticket\SupportSlaMetadata;
use Forwext\Core\Support\Ticket\SupportTicket;
use Forwext\Core\Support\Ticket\SupportTicketPriority;
use Forwext\Core\Support\Ticket\SupportTicketRepository;
use Forwext\Core\Support\Ticket\SupportTicketService;
use Forwext\Core\Support\Ticket\SupportTicketStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SupportTicketSubmissionServiceTest extends TestCase
{
    public function testDynamicSelectRejectsUnknownChoiceAndRequiredCheckboxRejectsMissingValue(): void
    {
        $select = new SupportFieldDefinition(
            'general',
            'issue_type',
            'Issue type',
            SupportFieldType::Select,
            true,
            ['technical'=>'Technical','account'=>'Account'],
            100,
            '',
            10,
            true,
        );
        $checkbox = new SupportFieldDefinition(
            'general',
            'confirmed',
            'Confirmed',
            SupportFieldType::Checkbox,
            true,
            [],
            1,
            '',
            20,
            true,
        );

        try {
            $select->validate('injected');
            self::fail('Unknown select option must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        $checkbox->validate(null);
    }

    public function testSubmissionPersistsDynamicFieldsContextAndSanitizedAttachment(): void
    {
        $actor = $this->id('1');
        $category = $this->category();
        $tickets = new IntakeTicketRepository($category);
        $intake = new IntakeMemoryRepository([
            new SupportFieldDefinition(
                'general',
                'issue_type',
                'Issue type',
                SupportFieldType::Select,
                true,
                ['technical'=>'Technical','account'=>'Account'],
                100,
                '',
                10,
                true,
            ),
        ]);
        $limits = new IntakeRateLimiter([true,true,true]);
        $storage = new IntakeStorage();
        $service = $this->service($actor, $tickets, $intake, $limits, $storage);

        $receipt = $service->submit(
            'general',
            'Broken feature',
            'The feature fails consistently.',
            ['issue_type'=>'technical'],
            SupportContextType::MarketplaceListing,
            $this->id('a'),
            [new SupportUpload('proof.txt', 'plain evidence')],
            $this->time('2026-09-18 16:00:00.000000'),
        );

        self::assertSame('technical', $receipt->fieldValues['issue_type']->value);
        self::assertSame(SupportContextType::MarketplaceListing, $receipt->context?->type);
        self::assertCount(1, $receipt->attachments);
        self::assertSame('text/plain', $receipt->attachments[0]->mediaType);
        self::assertSame('plain evidence', array_values($storage->objects)[0]);
        self::assertSame('The feature fails consistently.', $intake->description);
        self::assertSame(3, $limits->calls);
        self::assertCount(1, $tickets->created);
    }

    public function testUnknownFieldIsRejectedBeforeRateLimitOrTicketPersistence(): void
    {
        $actor = $this->id('1');
        $tickets = new IntakeTicketRepository($this->category());
        $intake = new IntakeMemoryRepository([]);
        $limits = new IntakeRateLimiter([true,true,true]);
        $storage = new IntakeStorage();
        $service = $this->service($actor, $tickets, $intake, $limits, $storage);

        $this->expectException(InvalidArgumentException::class);
        try {
            $service->submit(
                'general',
                'Subject',
                'Description',
                ['foreign_field'=>'injected'],
                now: $this->time('2026-09-18 16:00:00.000000'),
            );
        } finally {
            self::assertSame(0, $limits->calls);
            self::assertSame([], $tickets->created);
        }
    }

    public function testDuplicateRateLimitRejectsBeforeStorageAndTicketPersistence(): void
    {
        $actor = $this->id('1');
        $tickets = new IntakeTicketRepository($this->category());
        $intake = new IntakeMemoryRepository([]);
        $limits = new IntakeRateLimiter([true,true,false]);
        $storage = new IntakeStorage();
        $service = $this->service($actor, $tickets, $intake, $limits, $storage);

        $this->expectException(SupportSubmissionRateLimitException::class);
        try {
            $service->submit(
                'general',
                'Same subject',
                'Same description',
                uploads: [new SupportUpload('proof.txt', 'plain evidence')],
                now: $this->time('2026-09-18 16:00:00.000000'),
            );
        } finally {
            self::assertSame(3, $limits->calls);
            self::assertSame([], $tickets->created);
            self::assertSame([], $storage->objects);
        }
    }

    public function testAccountContextDoesNotExposeAnotherUserWithoutStaffPermission(): void
    {
        $actor = $this->id('1');
        $target = $this->id('2');
        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('find');
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new IntakePermissionRepository($actor, [])),
            new IntakeAssignmentProvider($actor),
        );
        $resolver = new AccountSupportContextResolver($users, $authorizer);

        $this->expectException(SupportContextUnavailableException::class);
        $resolver->resolve($actor, $target);
    }

    private function service(
        EntityId $actor,
        IntakeTicketRepository $tickets,
        IntakeMemoryRepository $intake,
        IntakeRateLimiter $limits,
        IntakeStorage $storage,
    ): SupportTicketSubmissionService {
        $database = new IntakeTransactionDatabase();
        $permissions = new IntakePermissionRepository($actor, [
            'support.ticket.create',
            'support.ticket.view_own',
        ]);
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine($permissions),
            new IntakeAssignmentProvider($actor),
        );
        $gate = new PermissionGate($authorizer, $actor);
        $ticketService = new SupportTicketService($database, $tickets, $gate, $authorizer);

        return new SupportTicketSubmissionService(
            $database,
            $ticketService,
            $intake,
            new SupportContextRegistry([new IntakeMarketplaceResolver()]),
            $limits,
            $storage,
            new AttachmentInspector(new ImageMetadataSanitizer(), new AttachmentQuotaPolicy()),
            $gate,
        );
    }

    private function category(): SupportCategory
    {
        return new SupportCategory(
            'general',
            'General',
            '',
            SupportTicketPriority::Normal,
            60,
            120,
            10,
            true,
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

final class IntakeTicketRepository implements SupportTicketRepository
{
    /** @var list<SupportTicket> */
    public array $created = [];

    public function __construct(private SupportCategory $category) {}
    public function activeCategories(): array { return [$this->category]; }
    public function category(string $key): ?SupportCategory { return $key === $this->category->key ? $this->category : null; }
    public function saveCategory(SupportCategory $category): void {}
    public function create(SupportTicket $ticket): void { $this->created[] = $ticket; }
    public function find(EntityId $ticketId): ?SupportTicket { return null; }
    public function forRequester(EntityId $requesterUserId, int $limit = 50): array { return []; }
    public function activeQueue(int $limit = 100): array { return []; }
    public function assign(EntityId $ticketId, ?EntityId $assignedUserId, int $expectedVersion, DateTimeImmutable $now): SupportTicket { throw new \LogicException(); }
    public function changePriority(EntityId $ticketId, SupportTicketPriority $priority, int $expectedVersion, DateTimeImmutable $now): SupportTicket { throw new \LogicException(); }
    public function changeStatus(EntityId $ticketId, SupportTicketStatus $status, SupportSlaMetadata $sla, ?DateTimeImmutable $closedAt, int $expectedVersion, DateTimeImmutable $now): SupportTicket { throw new \LogicException(); }
    public function markFirstResponse(EntityId $ticketId, DateTimeImmutable $firstRespondedAt, int $expectedVersion, DateTimeImmutable $now): SupportTicket { throw new \LogicException(); }
}

final class IntakeMemoryRepository implements SupportTicketIntakeRepository
{
    /** @var list<SupportFieldDefinition> */
    public array $definitions;
    public ?string $description = null;
    /** @var array<string,SupportFieldValue> */
    public array $values = [];
    public ?SupportContextLink $linkedContext = null;
    /** @var list<SupportAttachmentRecord> */
    public array $savedAttachments = [];

    /** @param list<SupportFieldDefinition> $definitions */
    public function __construct(array $definitions) { $this->definitions = $definitions; }
    public function activeFields(string $categoryKey): array { return $this->definitions; }
    public function saveFieldDefinition(SupportFieldDefinition $definition): void { $this->definitions[] = $definition; }
    public function saveIntake(EntityId $ticketId, string $description): void { $this->description = $description; }
    public function description(EntityId $ticketId): ?string { return $this->description; }
    public function saveFieldValues(EntityId $ticketId, array $values): void { $this->values = $values; }
    public function saveContext(EntityId $ticketId, SupportContextLink $context): void { $this->linkedContext = $context; }
    public function saveAttachment(SupportAttachmentRecord $attachment): void { $this->savedAttachments[] = $attachment; }
    public function fieldValues(EntityId $ticketId): array { return $this->values; }
    public function context(EntityId $ticketId): ?SupportContextLink { return $this->linkedContext; }
    public function attachments(EntityId $ticketId): array { return $this->savedAttachments; }
}

final class IntakeRateLimiter implements SupportSubmissionRateLimiter
{
    public int $calls = 0;
    /** @param list<bool> $results */
    public function __construct(private array $results) {}
    public function consume(string $scope, string $fingerprint, int $limit, int $windowSeconds, DateTimeImmutable $now): bool
    {
        $result = $this->results[$this->calls] ?? false;
        $this->calls++;
        return $result;
    }
}

final readonly class IntakeMarketplaceResolver implements SupportContextResolver
{
    public function type(): SupportContextType { return SupportContextType::MarketplaceListing; }
    public function resolve(EntityId $actorUserId, EntityId $targetId): SupportContextLink
    {
        return new SupportContextLink(SupportContextType::MarketplaceListing, $targetId, 'Listing');
    }
}

final class IntakeStorage implements StorageDriver
{
    /** @var array<string,string> */
    public array $objects = [];

    public function put(StoragePath $path, string $contents, StorageVisibility $visibility = StorageVisibility::Private, ?string $contentType = null): StoredObject
    {
        $this->objects[$path->value()] = $contents;
        return new StoredObject($path, $visibility, strlen($contents), hash('sha256', $contents), $contentType);
    }

    public function putStream(StoragePath $path, ReadableStream $stream, StorageVisibility $visibility = StorageVisibility::Private, ?string $contentType = null): StoredObject
    {
        return $this->put($path, $stream->contents(), $visibility, $contentType);
    }

    public function read(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): string
    {
        return $this->objects[$path->value()] ?? throw new \RuntimeException('Missing object.');
    }

    public function readStream(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): ReadableStream
    {
        return ReadableStream::fromString($this->read($path, $visibility));
    }

    public function metadata(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): ?StoredObject
    {
        if (!isset($this->objects[$path->value()])) return null;
        $contents = $this->objects[$path->value()];
        return new StoredObject($path, $visibility, strlen($contents), hash('sha256', $contents));
    }

    public function exists(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool
    {
        return isset($this->objects[$path->value()]);
    }

    public function delete(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool
    {
        if (!isset($this->objects[$path->value()])) return false;
        unset($this->objects[$path->value()]);
        return true;
    }

    public function publicUrl(StoragePath $path): ?string { return null; }
}

final class IntakeTransactionDatabase implements TransactionalQueryExecutor
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
        try { return $callback($this); } finally { $this->inside = $before; }
    }
}

final readonly class IntakeAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $actor) {}
    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $userId->equals($this->actor)
            ? new UserAccessAssignment($userId, EntityId::fromString(str_repeat('f', 32)))
            : null;
    }
}

final readonly class IntakePermissionRepository implements PermissionRuleRepository
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
        ) return [];
        return [new PermissionRule(PermissionSubjectType::User, $this->actor, PermissionEffect::Allow)];
    }
}
