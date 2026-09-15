<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Attachment;

use DateTimeImmutable;
use DateTimeZone;
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
use Forwext\Core\Forum\Attachment\AttachmentId;
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Forum\Attachment\AttachmentPermission;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Forum\Attachment\AttachmentRecord;
use Forwext\Core\Forum\Attachment\AttachmentRepository;
use Forwext\Core\Forum\Attachment\AttachmentService;
use Forwext\Core\Forum\Attachment\AttachmentState;
use Forwext\Core\Forum\Attachment\AttachmentThumbnailGenerator;
use Forwext\Core\Forum\Attachment\AttachmentUsage;
use Forwext\Core\Forum\Attachment\ImageMetadataSanitizer;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumSettings;
use Forwext\Core\Forum\Post\Post;
use Forwext\Core\Forum\Post\PostBody;
use Forwext\Core\Forum\Post\PostCounters;
use Forwext\Core\Forum\Post\PostPage;
use Forwext\Core\Forum\Post\PostPermission;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
use Forwext\Core\Storage\LocalStorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AttachmentServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/forwext-attachment-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/private', 0700, true);
        mkdir($this->root . '/public', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testStageFinalizeAndSecureDownloadVerifyPrivateStorageIntegrity(): void
    {
        [$service, $attachments, $storage, $forum, $post] = $this->service('1', [
            'forum.view',
            AttachmentPermission::Upload->value,
            AttachmentPermission::Download->value,
        ]);
        $now = $this->time('2026-09-15 22:00:00.000000');

        $temporary = $service->stage($forum->id(), '../notes.txt', 'hello attachment', $now);
        self::assertSame('notes.txt', $temporary->filename->value());
        self::assertSame(AttachmentState::Temporary, $temporary->state);
        self::assertTrue($storage->exists(StoragePath::fromString($temporary->storagePath), StorageVisibility::Private));

        $attached = $service->finalize($temporary->attachmentId, $post->id(), $now->modify('+1 minute'));
        self::assertSame(AttachmentState::Attached, $attached->state);
        self::assertSame($post->id()->value(), $attached->postId?->value());
        self::assertStringStartsWith('attachments/' . $forum->id()->value() . '/', $attached->storagePath);

        $download = $service->download($attached->attachmentId);
        self::assertSame('hello attachment', $download->contents);
        self::assertSame('text/plain', $download->mediaType);
        self::assertSame('notes.txt', $download->filename);

        $storage->put(
            StoragePath::fromString($attached->storagePath),
            'tampered',
            StorageVisibility::Private,
            'text/plain',
        );
        $this->expectException(AttachmentOperationException::class);
        $service->download($attached->attachmentId);
    }

    public function testExpiredTemporaryCleanupRemovesObjectBeforeMetadata(): void
    {
        [$service, $attachments, $storage, $forum] = $this->service('1', [
            'forum.view',
            AttachmentPermission::Upload->value,
        ]);
        $now = $this->time('2026-09-15 22:00:00.000000');
        $temporary = $service->stage($forum->id(), 'orphan.txt', 'temporary orphan', $now);
        $path = StoragePath::fromString($temporary->storagePath);

        $cleaned = $service->cleanupExpired($temporary->expiresAt->modify('+1 second'));

        self::assertSame(1, $cleaned);
        self::assertFalse($storage->exists($path, StorageVisibility::Private));
        self::assertNull($attachments->find($temporary->attachmentId));
    }

    public function testQuotaIsCheckedBeforeWritingStorage(): void
    {
        [$service, $attachments, $storage, $forum] = $this->service('1', [
            'forum.view',
            AttachmentPermission::Upload->value,
        ]);
        $attachments->usageOverride = new AttachmentUsage(20, 0, 0);

        try {
            $service->stage($forum->id(), 'blocked.txt', 'blocked', $this->time('2026-09-15 22:00:00.000000'));
            self::fail('Quota exhaustion must block staging.');
        } catch (AttachmentOperationException) {
            self::assertSame([], $this->filesUnder($this->root . '/private'));
        }
    }

    public function testNonOwnerCannotDownloadPendingPostWithoutModerationPermission(): void
    {
        [$ownerService, $attachments, $storage, $forum, $post, $threads, $posts, $nodes] = $this->service('1', [
            'forum.view', AttachmentPermission::Upload->value, AttachmentPermission::Download->value,
        ], pendingPost: true);
        $now = $this->time('2026-09-15 22:00:00.000000');
        $temporary = $ownerService->stage($forum->id(), 'pending.txt', 'pending body', $now);
        $attached = $ownerService->finalize($temporary->attachmentId, $post->id(), $now->modify('+1 minute'));

        $other = $this->id('2');
        $otherService = $this->buildService(
            $attachments,
            $storage,
            $threads,
            $posts,
            $nodes,
            $this->gate($other, $forum->id(), ['forum.view', AttachmentPermission::Download->value]),
        );

        $this->expectException(PermissionDeniedException::class);
        $otherService->download($attached->attachmentId);
    }

    /**
     * @param list<string> $permissions
     * @return array{AttachmentService,MemoryAttachmentRepository,LocalStorageDriver,ForumNode,Post,MemoryThreadRepository,MemoryPostRepository,MemoryAttachmentNodeRepository}
     */
    private function service(string $actorSeed, array $permissions, bool $pendingPost = false): array
    {
        $actor = $this->id($actorSeed);
        $forum = ForumNode::forum(
            $this->id('a'), null, 'Forum', ForumNodeSlug::fromString('forum'), new ForumSettings(),
        );
        $thread = Thread::create(
            $this->id('b'),
            $forum->id(),
            $this->id('1'),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Thread'),
            false,
            $this->time('2026-09-15 21:00:00.000000'),
        );
        $post = Post::create(
            $this->id('c'),
            $thread->id(),
            $this->id('1'),
            1,
            PostBody::fromString('Body'),
            $pendingPost,
            $this->time('2026-09-15 21:00:00.000000'),
        );
        $attachments = new MemoryAttachmentRepository();
        $threads = new MemoryThreadRepository([$thread]);
        $posts = new MemoryPostRepository([$post]);
        $nodes = new MemoryAttachmentNodeRepository([$forum]);
        $storage = new LocalStorageDriver($this->root . '/private', $this->root . '/public');
        $service = $this->buildService(
            $attachments,
            $storage,
            $threads,
            $posts,
            $nodes,
            $this->gate($actor, $forum->id(), $permissions),
        );
        return [$service, $attachments, $storage, $forum, $post, $threads, $posts, $nodes];
    }

    private function buildService(
        MemoryAttachmentRepository $attachments,
        LocalStorageDriver $storage,
        MemoryThreadRepository $threads,
        MemoryPostRepository $posts,
        MemoryAttachmentNodeRepository $nodes,
        PermissionGate $gate,
    ): AttachmentService {
        $quota = new AttachmentQuotaPolicy();
        return new AttachmentService(
            $attachments,
            $posts,
            $threads,
            $nodes,
            $storage,
            new AttachmentInspector(new ImageMetadataSanitizer(), $quota),
            new NullThumbnailGenerator(),
            $quota,
            $gate,
        );
    }

    /** @param list<string> $permissions */
    private function gate(EntityId $actor, EntityId $forumId, array $permissions): PermissionGate
    {
        $assignment = new UserAccessAssignment($actor, $this->id('f'));
        return new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new AttachmentPermissionRepository($actor, $forumId, $permissions)),
                new AttachmentAssignmentProvider($assignment),
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
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }

    /** @return list<string> */
    private function filesUnder(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

final class NullThumbnailGenerator implements AttachmentThumbnailGenerator
{
    public function generate(\Forwext\Core\Forum\Attachment\AttachmentInspection $inspection): ?\Forwext\Core\Forum\Attachment\AttachmentThumbnail
    {
        return null;
    }
}

final class MemoryAttachmentRepository implements AttachmentRepository
{
    /** @var array<string,AttachmentRecord> */
    private array $records = [];
    public ?AttachmentUsage $usageOverride = null;

    public function find(EntityId $attachmentId): ?AttachmentRecord
    {
        return $this->records[$attachmentId->value()] ?? null;
    }

    public function usageForUser(EntityId $userId): AttachmentUsage
    {
        if ($this->usageOverride !== null) {
            return $this->usageOverride;
        }
        $temporaryCount = 0;
        $temporaryBytes = 0;
        $stored = 0;
        foreach ($this->records as $record) {
            if (!$record->ownerUserId->equals($userId)) {
                continue;
            }
            $stored += $record->sizeBytes;
            if ($record->state === AttachmentState::Temporary) {
                ++$temporaryCount;
                $temporaryBytes += $record->sizeBytes;
            }
        }
        return new AttachmentUsage($temporaryCount, $temporaryBytes, $stored);
    }

    public function createTemporary(AttachmentRecord $attachment): void
    {
        $this->records[$attachment->attachmentId->value()] = $attachment;
    }

    public function finalize(EntityId $attachmentId, EntityId $ownerUserId, EntityId $postId, string $storagePath, ?string $thumbnailPath, DateTimeImmutable $attachedAt): void
    {
        $record = $this->find($attachmentId);
        if ($record === null || !$record->ownerUserId->equals($ownerUserId) || !$record->isTemporary()) {
            throw new LogicException('Attachment cannot be finalized.');
        }
        $this->records[$attachmentId->value()] = new AttachmentRecord(
            $record->attachmentId, $record->ownerUserId, $record->forumNodeId, $postId, $record->filename,
            $record->mediaType, $record->extension, $record->sizeBytes, $record->sha256, $storagePath,
            $thumbnailPath, $record->imageWidth, $record->imageHeight, $record->metadataStripped,
            AttachmentState::Attached, $record->createdAt, $record->expiresAt, $attachedAt,
        );
    }

    public function expiredTemporary(DateTimeImmutable $before, int $limit = 100): array
    {
        $matches = array_values(array_filter(
            $this->records,
            static fn (AttachmentRecord $record): bool => $record->isTemporary() && $record->expiresAt <= $before,
        ));
        usort($matches, static fn (AttachmentRecord $a, AttachmentRecord $b): int => $a->expiresAt <=> $b->expiresAt);
        return array_slice($matches, 0, $limit);
    }

    public function deleteTemporary(EntityId $attachmentId, EntityId $ownerUserId): void
    {
        $record = $this->find($attachmentId);
        if ($record !== null && $record->isTemporary() && $record->ownerUserId->equals($ownerUserId)) {
            unset($this->records[$attachmentId->value()]);
        }
    }
}

final class MemoryThreadRepository implements ThreadRepository
{
    /** @var array<string,Thread> */
    private array $threads = [];
    /** @param list<Thread> $threads */
    public function __construct(array $threads)
    {
        foreach ($threads as $thread) {
            $this->threads[$thread->id()->value()] = $thread;
        }
    }
    public function find(EntityId $threadId): ?Thread { return $this->threads[$threadId->value()] ?? null; }
    public function findByForum(EntityId $forumNodeId, int $limit = 50, int $offset = 0): array { return []; }
    public function save(Thread $thread): void { throw new LogicException('Not used.'); }
}

final class MemoryPostRepository implements PostRepository
{
    /** @var array<string,Post> */
    private array $posts = [];
    /** @param list<Post> $posts */
    public function __construct(array $posts)
    {
        foreach ($posts as $post) {
            $this->posts[$post->id()->value()] = $post;
        }
    }
    public function find(EntityId $postId): ?Post { return $this->posts[$postId->value()] ?? null; }
    public function firstPost(EntityId $threadId): ?Post { return null; }
    public function create(EntityId $threadId, EntityId $authorUserId, PostBody $body, bool $requiresApproval, bool $mustBeFirst, DateTimeImmutable $now): Post { throw new LogicException('Not used.'); }
    public function save(Post $post): void { throw new LogicException('Not used.'); }
    public function history(EntityId $postId, int $limit = 100, int $offset = 0): array { return []; }
    public function pageByThread(EntityId $threadId, int $page = 1, int $perPage = 20, bool $includeDeleted = false, bool $includeNonVisible = false): PostPage { return new PostPage([], $page, $perPage, 0); }
    public function counters(EntityId $threadId): PostCounters { return new PostCounters(0, 0); }
}

final class MemoryAttachmentNodeRepository implements ForumNodeRepository
{
    /** @param list<ForumNode> $nodes */
    public function __construct(private array $nodes) {}
    public function find(EntityId $nodeId): ?ForumNode { foreach ($this->nodes as $node) { if ($node->id()->equals($nodeId)) return $node; } return null; }
    public function findBySlug(ForumNodeSlug $slug): ?ForumNode { foreach ($this->nodes as $node) { if ($node->slug()->value() === $slug->value()) return $node; } return null; }
    public function all(): array { return $this->nodes; }
    public function save(ForumNode $node): void { throw new LogicException('Not used.'); }
    public function delete(EntityId $nodeId): void { throw new LogicException('Not used.'); }
}

final readonly class AttachmentAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment) {}
    public function find(EntityId $userId): ?UserAccessAssignment { return $this->assignment->userId()->equals($userId) ? $this->assignment : null; }
}

final class AttachmentPermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $allowed */
    public function __construct(private readonly EntityId $actor, private readonly EntityId $forumId, private readonly array $allowed) {}
    public function definition(PermissionKey $key): ?PermissionDefinition { return new PermissionDefinition($key, PermissionValueType::Flag); }
    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (!$assignment->userId()->equals($this->actor) || $nodeId === null || !$nodeId->equals($this->forumId) || !in_array($key->value(), $this->allowed, true)) {
            return [];
        }
        return [new PermissionRule(PermissionSubjectType::User, $this->actor, PermissionEffect::Allow, $nodeId)];
    }
}
