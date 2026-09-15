<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

use Forwext\Core\Profile\ProfileException;

final readonly class ProfileMusicPayload
{
    public function __construct(
        public string $contents,
        public string $contentType,
        public string $extension,
    ) {
        if ($contents === '' || !in_array($contentType, ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/mp4'], true)) {
            throw new ProfileException('Profile music payload is invalid.');
        }
        if (!in_array($extension, ['mp3', 'ogg', 'wav', 'm4a'], true)) {
            throw new ProfileException('Profile music payload extension is invalid.');
        }
    }
}
