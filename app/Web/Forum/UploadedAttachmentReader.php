<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use Forwext\Core\Http\Upload\UploadedFile;

interface UploadedAttachmentReader
{
    public function read(UploadedFile $file, int $maxBytes): string;
}
