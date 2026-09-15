<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\Upload\UploadedFile;

final class VerifiedUploadedAttachmentReader implements UploadedAttachmentReader
{
    public function read(UploadedFile $file, int $maxBytes): string
    {
        if ($maxBytes < 1) throw new HttpException('Attachment upload size policy is invalid.');
        if (!$file->isSuccessful()) throw new HttpException('Attachment upload did not complete successfully.');
        if ($file->size < 1 || $file->size > $maxBytes) throw new HttpException('Attachment upload exceeds the configured request limit.');
        if (!is_uploaded_file($file->temporaryPath)) throw new HttpException('Attachment source is not a verified PHP HTTP upload.');
        $handle = @fopen($file->temporaryPath, 'rb');
        if ($handle === false) throw new HttpException('Attachment upload cannot be opened.');
        $contents = '';
        try {
            while (!feof($handle)) {
                $remaining = ($maxBytes + 1) - strlen($contents);
                if ($remaining <= 0) throw new HttpException('Attachment upload exceeds the configured request limit.');
                $chunk = fread($handle, min(1_048_576, $remaining));
                if ($chunk === false) throw new HttpException('Attachment upload cannot be read.');
                if ($chunk !== '') $contents .= $chunk;
            }
        } finally {
            fclose($handle);
        }
        if (strlen($contents) !== $file->size) throw new HttpException('Attachment upload size does not match PHP upload metadata.');
        return $contents;
    }
}
