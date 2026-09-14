<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use DateTimeImmutable;

interface RegistrationInviteStore
{
    public function issue(int $maxUses, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now): string;

    public function consume(string $code, DateTimeImmutable $now): bool;
}
