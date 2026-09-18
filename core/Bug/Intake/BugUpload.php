<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Intake;

use Forwext\Core\Forum\Attachment\AttachmentFilename;

final readonly class BugUpload
{
    public AttachmentFilename $filename;

    public function __construct(
        string $clientFilename,
        public string $contents,
    ) {
        $this->filename = AttachmentFilename::fromClient($clientFilename);
    }
}
