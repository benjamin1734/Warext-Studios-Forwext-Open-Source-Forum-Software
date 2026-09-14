<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Upload;

use Forwext\Core\Http\HttpException;

final readonly class UploadedFile
{
    public function __construct(
        public string $temporaryPath,
        public ?string $clientFilename,
        public ?string $clientMediaType,
        public int $size,
        public int $error,
    ) {
        if ($size < 0) {
            throw new HttpException('Uploaded file size may not be negative.');
        }

        if ($error < UPLOAD_ERR_OK || $error > UPLOAD_ERR_EXTENSION) {
            throw new HttpException('Uploaded file error code is invalid.');
        }

        foreach ([$clientFilename ?? '', $clientMediaType ?? ''] as $clientValue) {
            if (str_contains($clientValue, "\0")) {
                throw new HttpException('Uploaded file metadata may not contain NUL characters.');
            }
        }
    }

    public function isSuccessful(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    public function moveTo(string $targetPath): void
    {
        if (!$this->isSuccessful()) {
            throw new HttpException('Cannot move an upload that did not complete successfully.');
        }

        if ($targetPath === '' || str_contains($targetPath, "\0")) {
            throw new HttpException('Upload target path is invalid.');
        }

        if (!is_uploaded_file($this->temporaryPath)) {
            throw new HttpException('Upload source is not a verified PHP HTTP upload.');
        }

        if (!move_uploaded_file($this->temporaryPath, $targetPath)) {
            throw new HttpException('Unable to move the uploaded file.');
        }
    }
}
