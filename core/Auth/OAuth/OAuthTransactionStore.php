<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use DateTimeImmutable;

interface OAuthTransactionStore
{
    public function create(OAuthTransaction $transaction): void;

    public function consume(
        string $stateHash,
        string $providerId,
        string $redirectUri,
        DateTimeImmutable $now,
    ): ?OAuthTransaction;
}
