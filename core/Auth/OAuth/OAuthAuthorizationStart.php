<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

final readonly class OAuthAuthorizationStart
{
    public function __construct(public string $authorizationUrl, public string $state)
    {
    }
}
