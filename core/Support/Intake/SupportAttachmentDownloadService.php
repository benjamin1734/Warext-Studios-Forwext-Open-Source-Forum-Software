<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentDownload;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Forwext\Core\Support\Conversation\SupportConversationOperationException;
use Forwext\Core\Support\Ticket\SupportTicketService;

final readonly class SupportAttachmentDownloadService
{
    public function __construct(
        private SupportTicketService $tickets,
        private SupportTicketIntakeRepository $intake,
        private StorageDriver $storage,
    ) {
    }

    public function download(EntityId $ticketId, EntityId $attachmentId): AttachmentDownload
    {
        $this->tickets->ticket($ticketId);
        $record = null;
        foreach ($this->intake->attachments($ticketId) as $candidate) {
            if ($candidate->attachmentId->equals($attachmentId)) {
                $record = $candidate;
                break;
            }
        }
        if ($record === null) {
            throw new SupportConversationOperationException('Support attachment was not found.');
        }

        $contents = $this->storage->read(
            StoragePath::fromString($record->storagePath),
            StorageVisibility::Private,
        );
        if (strlen($contents) !== $record->sizeBytes
            || !hash_equals($record->sha256, hash('sha256', $contents))
        ) {
            throw new SupportConversationOperationException('Support attachment integrity check failed.');
        }

        return new AttachmentDownload(
            $contents,
            $record->mediaType,
            $record->filename->value(),
            false,
        );
    }
}
