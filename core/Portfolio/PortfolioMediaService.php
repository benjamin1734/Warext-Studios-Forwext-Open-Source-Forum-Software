<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use InvalidArgumentException;
use Throwable;

final readonly class PortfolioMediaService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private PortfolioService $portfolio,
        private PortfolioRepository $repository,
        private StorageDriver $storage,
        private AttachmentInspector $inspector,
        private AttachmentQuotaPolicy $quota,
    ) {
    }

    public function upload(
        EntityId $actor,
        EntityId $projectId,
        string $contents,
        string $alt,
        DateTimeImmutable $at,
    ): PortfolioMedia {
        $project = $this->portfolio->project($projectId, $actor);
        if (!$this->portfolio->canManageProject($actor, $project)) {
            throw new PermissionDeniedException($this->portfolio->decision($actor, 'portfolio.manage_own'));
        }
        if (preg_match('//u', $alt) !== 1 || strlen($alt) > 200) {
            throw new InvalidArgumentException('Portfolio media alternative text is invalid.');
        }

        $inspection = $this->inspector->inspect($contents);
        if (!str_starts_with($inspection->mediaType, 'image/')) {
            throw new AttachmentOperationException('Portfolio media must be an image.');
        }

        $count = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_portfolio_media WHERE project_id=:project_id',
            ['project_id' => $projectId->value()],
        ));
        if ($count >= 12) {
            throw new AttachmentOperationException('Portfolio media limit has been reached.');
        }

        $stored = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COALESCE(SUM(m.size_bytes),0) FROM forwext_portfolio_media m '
            . 'INNER JOIN forwext_portfolio_projects p ON p.project_id=m.project_id '
            . 'WHERE p.owner_user_id=:owner_user_id AND m.size_bytes IS NOT NULL',
            ['owner_user_id' => $project->ownerUserId->value()],
        ));
        if ($stored + $inspection->sizeBytes > $this->quota->maxStoredBytes) {
            throw new AttachmentOperationException('Portfolio media storage quota has been reached.');
        }

        $mediaId = EntityId::fromString(bin2hex(random_bytes(16)));
        $storagePath = StoragePath::fromString(sprintf(
            'portfolio/%s/%s/%s/%s.%s',
            $project->ownerUserId->value(),
            $projectId->value(),
            $mediaId->value(),
            $inspection->sha256,
            $inspection->extension,
        ));
        $this->storage->put(
            $storagePath,
            $inspection->contents,
            StorageVisibility::Private,
            $inspection->mediaType,
        );

        $path = '/portfolio/media/' . $mediaId->value();
        $sortOrder = min(65535, ($count + 1) * 10);
        try {
            $this->database->transaction(function () use (
                $mediaId,
                $projectId,
                $path,
                $storagePath,
                $inspection,
                $alt,
                $sortOrder,
                $actor,
            ): void {
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_portfolio_media '
                    . '(media_id,project_id,path,storage_path,media_type,extension,size_bytes,sha256_hex,'
                    . 'alt_text,sort_order,created_at_utc) '
                    . 'VALUES (:media_id,:project_id,:path,:storage_path,:media_type,:extension,:size_bytes,:sha256,'
                    . ':alt,:sort_order,UTC_TIMESTAMP(6))',
                    [
                        'media_id' => $mediaId->value(),
                        'project_id' => $projectId->value(),
                        'path' => $path,
                        'storage_path' => $storagePath->value(),
                        'media_type' => $inspection->mediaType,
                        'extension' => $inspection->extension,
                        'size_bytes' => $inspection->sizeBytes,
                        'sha256' => $inspection->sha256,
                        'alt' => $alt,
                        'sort_order' => $sortOrder,
                    ],
                ));
                $this->repository->recordHistory($projectId, $actor, 'media.upload', null, null);
            });
        } catch (Throwable $exception) {
            try {
                $this->storage->delete($storagePath, StorageVisibility::Private);
            } catch (Throwable) {
            }
            throw $exception;
        }

        return new PortfolioMedia($path, $alt, $sortOrder, $mediaId);
    }

    public function delete(EntityId $actor, EntityId $projectId, EntityId $mediaId): void
    {
        $project = $this->portfolio->project($projectId, $actor);
        if (!$this->portfolio->canManageProject($actor, $project)) {
            throw new PermissionDeniedException($this->portfolio->decision($actor, 'portfolio.manage_own'));
        }

        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT storage_path FROM forwext_portfolio_media '
            . 'WHERE media_id=:media_id AND project_id=:project_id LIMIT 1',
            ['media_id' => $mediaId->value(), 'project_id' => $projectId->value()],
        ));
        if ($row === null) {
            throw new InvalidArgumentException('Portfolio media was not found.');
        }

        $path = is_string($row['storage_path'] ?? null) && $row['storage_path'] !== ''
            ? StoragePath::fromString((string) $row['storage_path'])
            : null;

        $this->database->transaction(function () use ($mediaId, $projectId, $actor): void {
            $changed = $this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_portfolio_media WHERE media_id=:media_id AND project_id=:project_id',
                ['media_id' => $mediaId->value(), 'project_id' => $projectId->value()],
            ));
            if ($changed !== 1) {
                throw new InvalidArgumentException('Portfolio media was not found.');
            }
            $this->repository->recordHistory($projectId, $actor, 'media.delete', null, null);
        });

        if ($path !== null) {
            try {
                $this->storage->delete($path, StorageVisibility::Private);
            } catch (Throwable) {
            }
        }
    }

    public function download(EntityId $mediaId, ?EntityId $actor): PortfolioMediaDownload
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT media_id,project_id,storage_path,media_type,extension,size_bytes,sha256_hex '
            . 'FROM forwext_portfolio_media WHERE media_id=:media_id LIMIT 1',
            ['media_id' => $mediaId->value()],
        ));
        if ($row === null) {
            throw new InvalidArgumentException('Portfolio media was not found.');
        }

        $projectId = EntityId::fromString((string) $row['project_id']);
        $this->portfolio->project($projectId, $actor);

        $storagePath = $row['storage_path'] ?? null;
        $mediaType = $row['media_type'] ?? null;
        $extension = $row['extension'] ?? null;
        $size = $row['size_bytes'] ?? null;
        $sha256 = $row['sha256_hex'] ?? null;
        if (
            !is_string($storagePath) || $storagePath === ''
            || !is_string($mediaType) || !str_starts_with($mediaType, 'image/')
            || !is_string($extension) || preg_match('/^[a-z0-9]{1,10}$/D', $extension) !== 1
            || !is_numeric($size) || (int) $size < 1
            || !is_string($sha256) || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
        ) {
            throw new InvalidArgumentException('Portfolio media record is incomplete.');
        }

        $contents = $this->storage->read(StoragePath::fromString($storagePath), StorageVisibility::Private);
        if (strlen($contents) !== (int) $size || !hash_equals($sha256, hash('sha256', $contents))) {
            throw new AttachmentOperationException('Portfolio media integrity verification failed.');
        }

        return new PortfolioMediaDownload(
            $contents,
            $mediaType,
            'portfolio-' . $mediaId->value() . '.' . $extension,
        );
    }
}
