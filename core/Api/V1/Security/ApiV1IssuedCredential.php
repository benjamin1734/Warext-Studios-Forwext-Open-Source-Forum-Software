<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1\Security;

final readonly class ApiV1IssuedCredential
{
    public function __construct(
        public string $secret,
        public ApiV1CredentialRecord $record,
    ) {
    }
}
