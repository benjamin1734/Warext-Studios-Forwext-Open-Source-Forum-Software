<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ConnectedAccountStore
{
    public function find(string $providerId, string $subject): ?ConnectedAccount;
    public function findForUser(EntityId $userId, string $providerId): ?ConnectedAccount;
    public function countForUser(EntityId $userId): int;
    public function link(ConnectedAccount $account): void;
    public function touch(string $providerId, string $subject, DateTimeImmutable $authenticatedAt): void;
    public function unlink(EntityId $userId, string $providerId): bool;
}
