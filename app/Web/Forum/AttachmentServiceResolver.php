<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Forum\Attachment\AttachmentRepository;
use Forwext\Core\Forum\Attachment\AttachmentService;
use Forwext\Core\Forum\Attachment\AttachmentThumbnailGenerator;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Post\PostRepository;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Storage\StorageDriver;

final readonly class AttachmentServiceResolver
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
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function forActor(EntityId $actorId): AttachmentService
    {
        return new AttachmentService(
            $this->attachments,
            $this->posts,
            $this->threads,
            $this->nodes,
            $this->storage,
            $this->inspector,
            $this->thumbnails,
            $this->quota,
            new PermissionGate($this->authorizer, $actorId),
        );
    }

    public function quota(): AttachmentQuotaPolicy
    {
        return $this->quota;
    }
}
