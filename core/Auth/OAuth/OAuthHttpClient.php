<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

interface OAuthHttpClient
{
    /** @param array<string, string> $form @return array<string, mixed> */
    public function postForm(string $url, array $form): array;

    /** @return array<string, mixed> */
    public function getBearerJson(string $url, string $accessToken): array;
}
