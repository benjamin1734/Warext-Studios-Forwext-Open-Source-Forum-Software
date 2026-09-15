<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Post\PostModerationState;
use Forwext\Core\Forum\Post\PostPermission;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Throwable;

final readonly class AttachmentService
{
    public function __construct(
        private AttachmentRepository $attachments,
        private PostRepository $posts,
        private ThreadRepository $threads,
        private ForumNodeRepository $nodes,
        private StorageDriver $storage,
        private AttachmentInspector $inspector,
        private AttachmentThumbnailGenerator $thumbnails,
        private AttachmentQuotaPolicy $quota,
        private PermissionGate $gate,
    ) {
    }

    public function stage(
        EntityId $forumNodeId,
        string $clientFilename,
        string $contents,
        DateTimeImmutable $now,
    ): AttachmentRecord {
        $this->requireForum($forumNodeId, AttachmentPermission::Upload);
        $owner = $this->gate->actorId();
        $filename = AttachmentFilename::fromClient($clientFilename);
        $inspection = $this->inspector->inspect($contents);

        $usage = $this->attachments->usageForUser($owner);
        if ($usage->temporaryCount >= $this->quota->maxTemporaryCount
            || $usage->temporaryBytes + $inspection->sizeBytes > $this->quota->maxTemporaryBytes
            || $usage->storedBytes + $inspection->sizeBytes > $this->quota->maxStoredBytes
        ) {
            throw new AttachmentOperationException('Attachment quota has been reached.');
        }

        $now = $this->utc($now);
        $id = AttachmentId::generate();
        $base = sprintf('attachments/tmp/%s/%s', $owner->value(), $id->value());
        $storagePath = StoragePath::fromString($base . '/' . $inspection->sha256 . '.' . $inspection->extension);
        $thumbnail = $this->thumbnails->generate($inspection);
        $thumbnailPath = $thumbnail === null
            ? null
            : StoragePath::fromString($base . '/thumb.' . $thumbnail->extension);

        $this->storage->put(
            $storagePath,
            $inspection->contents,
            StorageVisibility::Private,
            $inspection->mediaType,
        );
        try {
            if ($thumbnail !== null && $thumbnailPath !== null) {
                $this->storage->put(
                    $thumbnailPath,
                    $thumbnail->contents,
                    StorageVisibility::Private,
                    $thumbnail->mediaType,
                );
            }
            $expiresAt = $now->add(new DateInterval('PT' . $this->quota->temporaryTtlSeconds . 'S'));
            $record = new AttachmentRecord(
                $id,
                $owner,
                $forumNodeId,
                null,
                $filename,
                $inspection->mediaType,
                $inspection->extension,
                $inspection->sizeBytes,
                $inspection->sha256,
                $storagePath->value(),
                $thumbnailPath?->value(),
                $inspection->imageWidth,
                $inspection->imageHeight,
                $inspection->metadataStripped,
                AttachmentState::Temporary,
                $now,
                $expiresAt,
                null,
            );
            $this->attachments->createTemporary($record);
            return $record;
        } catch (Throwable $exception) {
            $this->safeDelete($thumbnailPath);
            $this->safeDelete($storagePath);
            throw $exception;
        }
    }

    public function finalize(EntityId $attachmentId, EntityId $postId, DateTimeImmutable $now): AttachmentRecord
    {
        AttachmentId::assert($attachmentId);
        $record = $this->attachments->find($attachmentId)
            ?? throw new AttachmentOperationException('Attachment is not available.');
        if (!$record->isTemporary()) {
            throw new AttachmentOperationException('Attachment is no longer temporary.');
        }
        $actor = $this->gate->actorId();
        if (!$record->ownerUserId->equals($actor)) {
            $this->gate->require(AttachmentPermission::ManageAny->key(), $record->forumNodeId);
        }
        $now = $this->utc($now);
        if ($record->expiresAt <= $now) {
            throw new AttachmentOperationException('Temporary attachment has expired.');
        }

        $post = $this->posts->find($postId)
            ?? throw new AttachmentOperationException('Attachment target post is not available.');
        $thread = $this->threads->find($post->threadId())
            ?? throw new AttachmentOperationException('Attachment target thread is not active.');
        if (!$thread->forumNodeId()->equals($record->forumNodeId)) {
            throw new AttachmentOperationException('Attachment cannot cross its authorized forum boundary.');
        }
        $this->requireForum($record->forumNodeId, AttachmentPermission::Upload);
        if ($post->authorUserId() === null || !$post->authorUserId()->equals($actor)) {
            $this->gate->require(PostPermission::EditAny->key(), $record->forumNodeId);
        }

        $nonce = bin2hex(random_bytes(8));
        $base = sprintf(
            'attachments/%s/%s/%s/%s',
            $record->forumNodeId->value(),
            $post->id()->value(),
            $record->attachmentId->value(),
            $nonce,
        );
        $newPath = StoragePath::fromString($base . '/' . $record->sha256 . '.' . $record->extension);
        $newThumbnailPath = $record->thumbnailPath === null
            ? null
            : StoragePath::fromString($base . '/thumb.' . pathinfo($record->thumbnailPath, PATHINFO_EXTENSION));
        $oldPath = StoragePath::fromString($record->storagePath);
        $oldThumbnailPath = $record->thumbnailPath === null ? null : StoragePath::fromString($record->thumbnailPath);
        $original = $this->readVerifiedOriginal($record);

        $this->storage->put(
            $newPath,
            $original,
            StorageVisibility::Private,
            $record->mediaType,
        );
        try {
            if ($newThumbnailPath !== null && $oldThumbnailPath !== null) {
                $this->storage->put(
                    $newThumbnailPath,
                    $this->storage->read($oldThumbnailPath, StorageVisibility::Private),
                    StorageVisibility::Private,
                    $this->thumbnailMediaType($newThumbnailPath->value()),
                );
            }
            $this->attachments->finalize(
                $record->attachmentId,
                $record->ownerUserId,
                $post->id(),
                $newPath->value(),
                $newThumbnailPath?->value(),
                $now,
            );
        } catch (Throwable $exception) {
            $this->safeDelete($newThumbnailPath);
            $this->safeDelete($newPath);
            throw $exception;
        }

        $this->safeDelete($oldThumbnailPath);
        $this->safeDelete($oldPath);
        return $this->attachments->find($attachmentId)
            ?? throw new AttachmentOperationException('Finalized attachment could not be reloaded.');
    }

    public function download(EntityId $attachmentId, bool $thumbnail = false): AttachmentDownload
    {
        AttachmentId::assert($attachmentId);
        $record = $this->attachments->find($attachmentId)
            ?? throw new AttachmentOperationException('Attachment is not available.');
        if ($record->state !== AttachmentState::Attached || $record->postId === null) {
            throw new AttachmentOperationException('Attachment is not available for download.');
        }

        $post = $this->posts->find($record->postId)
            ?? throw new AttachmentOperationException('Attachment post is not available.');
        $thread = $this->threads->find($post->threadId())
            ?? throw new AttachmentOperationException('Attachment thread is not active.');
        if (!$thread->forumNodeId()->equals($record->forumNodeId)) {
            throw new AttachmentOperationException('Attachment forum binding is invalid.');
        }
        $this->requireForum($record->forumNodeId, AttachmentPermission::Download);

        $actor = $this->gate->actorId();
        $restricted = $post->isDeleted() || $post->moderationState() !== PostModerationState::Visible;
        if ($restricted && !$record->ownerUserId->equals($actor)) {
            $this->gate->require(PostPermission::Moderate->key(), $record->forumNodeId);
        }

        if ($thumbnail && $record->thumbnailPath !== null) {
            $contents = $this->storage->read(
                StoragePath::fromString($record->thumbnailPath),
                StorageVisibility::Private,
            );
            $extension = strtolower((string) pathinfo($record->thumbnailPath, PATHINFO_EXTENSION));
            $stem = (string) pathinfo($record->filename->value(), PATHINFO_FILENAME);
            if ($stem === '') {
                $stem = 'attachment';
            }
            return new AttachmentDownload(
                $contents,
                $this->thumbnailMediaType($record->thumbnailPath),
                $stem . '-thumb.' . $extension,
                true,
            );
        }

        return new AttachmentDownload(
            $this->readVerifiedOriginal($record),
            $record->mediaType,
            $record->filename->value(),
            false,
        );
    }

    public function cleanupExpired(DateTimeImmutable $now, int $limit = 100): int
    {
        return (new AttachmentCleanupService($this->attachments, $this->storage))->cleanupExpired(
            $this->utc($now),
            $limit,
        );
    }

    private function requireForum(EntityId $forumNodeId, AttachmentPermission $permission): void
    {
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $node = $hierarchy->find($forumNodeId);
        if ($node === null || $node->type() !== ForumNodeType::Forum) {
            throw new AttachmentOperationException('Attachment forum is not available.');
        }
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forumNodeId);
        $this->gate->require($permission->key(), $forumNodeId);
    }

    private function readVerifiedOriginal(AttachmentRecord $record): string
    {
        $contents = $this->storage->read(
            StoragePath::fromString($record->storagePath),
            StorageVisibility::Private,
        );
        if (strlen($contents) !== $record->sizeBytes || !hash_equals($record->sha256, hash('sha256', $contents))) {
            throw new AttachmentOperationException('Stored attachment integrity check failed.');
        }
        return $contents;
    }

    private function safeDelete(?StoragePath $path): void
    {
        if ($path === null) {
            return;
        }
        try {
            $this->storage->delete($path, StorageVisibility::Private);
        } catch (Throwable) {
        }
    }

    private function thumbnailMediaType(string $path): string
    {
        return str_ends_with(strtolower($path), '.jpg') ? 'image/jpeg' : 'image/png';
    }

    private function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
