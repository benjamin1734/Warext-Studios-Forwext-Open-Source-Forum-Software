<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use InvalidArgumentException;

final readonly class LegalDocumentRequirement
{
    public function __construct(
        public string $type,
        public string $version,
        public string $contentSha256,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $type) !== 1) {
            throw new InvalidArgumentException('Legal document type is invalid.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $version) !== 1) {
            throw new InvalidArgumentException('Legal document version is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $contentSha256) !== 1) {
            throw new InvalidArgumentException('Legal document content hash is invalid.');
        }
    }
}
