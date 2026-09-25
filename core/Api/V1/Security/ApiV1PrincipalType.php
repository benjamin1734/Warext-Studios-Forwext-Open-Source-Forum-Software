<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1\Security;

enum ApiV1PrincipalType: string
{
    case PersonalToken = 'personal_token';
    case ApiKey = 'api_key';
    case OAuth = 'oauth';

    public function secretPrefix(): string
    {
        return match ($this) {
            self::PersonalToken => 'fxpat_',
            self::ApiKey => 'fxkey_',
            self::OAuth => 'fxoauth_',
        };
    }

    public function acceptsBearer(): bool
    {
        return $this !== self::ApiKey;
    }
}
