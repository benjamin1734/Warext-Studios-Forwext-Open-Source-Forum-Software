<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Node\ForumNodeId;
use Forwext\Core\Forum\Post\PostId;
use Forwext\Core\Storage\StoragePath;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseAttachmentRepository implements AttachmentRepository
{
    private AttachmentQuotaPolicy $quota;

    public function __construct(
        private TransactionalQueryExecutor $database,
        ?AttachmentQuotaPolicy $quota = null,
    ) {
        $this->quota = $quota ?? new AttachmentQuotaPolicy();
    }

    public function find(EntityId $attachmentId): ?AttachmentRecord
    {
        AttachmentId::assert($attachmentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            $this->selectSql() . ' WHERE `attachment_id` = :attachment_id LIMIT 1',
            ['attachment_id' => $attachmentId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function usageForUser(EntityId $userId): AttachmentUsage
    {
        UserId::assert($userId);
        return $this->usageForUserWith($this->database, $userId);
    }

    public function createTemporary(AttachmentRecord $attachment): void
    {
        if (!$attachment->isTemporary() || $attachment->postId !== null || $attachment->attachedAt !== null) {
            throw new InvalidArgumentException('Only unbound temporary attachments can be created.');
        }

        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($attachment): void {
            $owner = $database->fetchOne(new CompiledQuery(
                'SELECT `user_id` FROM `forwext_users` WHERE `user_id` = :owner_user_id FOR UPDATE',
                ['owner_user_id' => $attachment->ownerUserId->value()],
                true,
            ));
            if ($owner === null) {
                throw new RuntimeException('Attachment owner is not available.');
            }

            $usage = $this->usageForUserWith($database, $attachment->ownerUserId);
            if ($usage->temporaryCount >= $this->quota->maxTemporaryCount
                || $usage->temporaryBytes + $attachment->sizeBytes > $this->quota->maxTemporaryBytes
                || $usage->storedBytes + $attachment->sizeBytes > $this->quota->maxStoredBytes
            ) {
                throw new AttachmentOperationException('Attachment quota has been reached.');
            }

            $affected = $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_attachments` '
                . '(`attachment_id`, `owner_user_id`, `forum_node_id`, `post_id`, `filename`, `media_type`, '
                . '`extension`, `size_bytes`, `sha256_hex`, `storage_path`, `thumbnail_path`, `image_width`, '
                . '`image_height`, `metadata_stripped`, `state`, `created_at_utc`, `expires_at_utc`, `attached_at_utc`) '
                . 'VALUES (:attachment_id, :owner_user_id, :forum_node_id, NULL, :filename, :media_type, '
                . ':extension, :size_bytes, :sha256_hex, :storage_path, :thumbnail_path, :image_width, '
                . ":image_height, :metadata_stripped, 'temporary', :created_at, :expires_at, NULL)",
                [
                    'attachment_id' => $attachment->attachmentId->value(),
                    'owner_user_id' => $attachment->ownerUserId->value(),
                    'forum_node_id' => $attachment->forumNodeId->value(),
                    'filename' => $attachment->filename->value(),
                    'media_type' => $attachment->mediaType,
                    'extension' => $attachment->extension,
                    'size_bytes' => $attachment->sizeBytes,
                    'sha256_hex' => $attachment->sha256,
                    'storage_path' => $attachment->storagePath,
                    'thumbnail_path' => $attachment->thumbnailPath,
                    'image_width' => $attachment->imageWidth,
                    'image_height' => $attachment->imageHeight,
                    'metadata_stripped' => $attachment->metadataStripped,
                    'created_at' => self::format($attachment->createdAt),
                    'expires_at' => self::format($attachment->expiresAt),
                ],
            ));
            if ($affected !== 1) {
                throw new RuntimeException('Temporary attachment persistence failed.');
            }
        });
    }

    public function finalize(
        EntityId $attachmentId,
        EntityId $ownerUserId,
        EntityId $postId,
        string $storagePath,
        ?string $thumbnailPath,
        DateTimeImmutable $attachedAt,
    ): void {
        AttachmentId::assert($attachmentId);
        UserId::assert($ownerUserId);
        PostId::assert($postId);
        StoragePath::fromString($storagePath);
        if ($thumbnailPath !== null) {
            StoragePath::fromString($thumbnailPath);
        }
        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_attachments` SET `post_id` = :post_id, `storage_path` = :storage_path, '
            . "`thumbnail_path` = :thumbnail_path, `state` = 'attached', `attached_at_utc` = :attached_at "
            . "WHERE `attachment_id` = :attachment_id AND `owner_user_id` = :owner_user_id AND `state` = 'temporary'",
            [
                'post_id' => $postId->value(),
                'storage_path' => $storagePath,
                'thumbnail_path' => $thumbnailPath,
                'attached_at' => self::format($attachedAt),
                'attachment_id' => $attachmentId->value(),
                'owner_user_id' => $ownerUserId->value(),
            ],
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Temporary attachment could not be finalized.');
        }
    }

    public function expiredTemporary(DateTimeImmutable $before, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Attachment cleanup limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            $this->selectSql()
            . " WHERE `state` = 'temporary' AND `expires_at_utc` <= :before "
            . 'ORDER BY `expires_at_utc` ASC, `attachment_id` ASC LIMIT ' . $limit,
            ['before' => self::format($before)],
        ));
        return array_map($this->hydrate(...), $rows);
    }

    public function deleteTemporary(EntityId $attachmentId, EntityId $ownerUserId): void
    {
        AttachmentId::assert($attachmentId);
        UserId::assert($ownerUserId);
        $this->database->execute(new CompiledQuery(
            "DELETE FROM `forwext_attachments` WHERE `attachment_id` = :attachment_id "
            . "AND `owner_user_id` = :owner_user_id AND `state` = 'temporary'",
            ['attachment_id' => $attachmentId->value(), 'owner_user_id' => $ownerUserId->value()],
        ));
    }

    private function usageForUserWith(TransactionalQueryExecutor $database, EntityId $userId): AttachmentUsage
    {
        $row = $database->fetchOne(new CompiledQuery(
            'SELECT '
            . "SUM(CASE WHEN `state` = 'temporary' THEN 1 ELSE 0 END) AS `temporary_count`, "
            . "SUM(CASE WHEN `state` = 'temporary' THEN `size_bytes` ELSE 0 END) AS `temporary_bytes`, "
            . 'COALESCE(SUM(`size_bytes`), 0) AS `stored_bytes` '
            . 'FROM `forwext_attachments` WHERE `owner_user_id` = :owner_user_id',
            ['owner_user_id' => $userId->value()],
        ));
        return new AttachmentUsage(
            (int) ($row['temporary_count'] ?? 0),
            (int) ($row['temporary_bytes'] ?? 0),
            (int) ($row['stored_bytes'] ?? 0),
        );
    }

    private function selectSql(): string
    {
        return 'SELECT `attachment_id`, `owner_user_id`, `forum_node_id`, `post_id`, `filename`, '
            . '`media_type`, `extension`, `size_bytes`, `sha256_hex`, `storage_path`, `thumbnail_path`, '
            . '`image_width`, `image_height`, `metadata_stripped`, `state`, `created_at_utc`, '
            . '`expires_at_utc`, `attached_at_utc` FROM `forwext_attachments`';
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): AttachmentRecord
    {
        return new AttachmentRecord(
            AttachmentId::fromStored((string) $row['attachment_id']),
            UserId::fromStored((string) $row['owner_user_id']),
            ForumNodeId::fromStored((string) $row['forum_node_id']),
            ($row['post_id'] ?? null) === null ? null : PostId::fromStored((string) $row['post_id']),
            AttachmentFilename::fromClient((string) $row['filename']),
            (string) $row['media_type'],
            (string) $row['extension'],
            (int) $row['size_bytes'],
            (string) $row['sha256_hex'],
            (string) $row['storage_path'],
            ($row['thumbnail_path'] ?? null) === null ? null : (string) $row['thumbnail_path'],
            ($row['image_width'] ?? null) === null ? null : (int) $row['image_width'],
            ($row['image_height'] ?? null) === null ? null : (int) $row['image_height'],
            (bool) $row['metadata_stripped'],
            AttachmentState::from((string) $row['state']),
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['expires_at_utc']),
            ($row['attached_at_utc'] ?? null) === null ? null : self::parse((string) $row['attached_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored attachment timestamp is invalid.');
        }
        return $parsed;
    }
}
