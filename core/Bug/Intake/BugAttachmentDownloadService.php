<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use Forwext\Core\Bug\Report\BugReportOperationException;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentDownload;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;

final readonly class BugAttachmentDownloadService
{
    public function __construct(
        private BugReportService $reports,
        private BugReportIntakeRepository $intake,
        private StorageDriver $storage,
    ) {
    }

    public function download(EntityId $reportId, EntityId $attachmentId): AttachmentDownload
    {
        $this->reports->report($reportId);

        $record = null;
        foreach ($this->intake->attachments($reportId) as $candidate) {
            if ($candidate->attachmentId->equals($attachmentId)) {
                $record = $candidate;
                break;
            }
        }
        if ($record === null) {
            throw new BugReportOperationException('Bug report attachment was not found.');
        }

        $contents = $this->storage->read(
            StoragePath::fromString($record->storagePath),
            StorageVisibility::Private,
        );
        if (strlen($contents) !== $record->sizeBytes
            || !hash_equals($record->sha256, hash('sha256', $contents))
        ) {
            throw new BugReportOperationException('Bug report attachment integrity check failed.');
        }

        return new AttachmentDownload(
            $contents,
            $record->mediaType,
            $record->filename->value(),
            false,
        );
    }
}
