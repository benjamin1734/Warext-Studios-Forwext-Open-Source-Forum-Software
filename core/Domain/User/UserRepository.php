<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use Forwext\Core\Domain\Entity\EntityId;

interface UserRepository
{
    public function find(EntityId $id): ?User;

    public function findByUsername(Username $username): ?User;

    public function findByEmail(EmailAddress $email): ?User;

    public function save(User $user): void;

    /** @return list<UserHistoryEntry> */
    public function history(EntityId $id, int $limit = 100, int $offset = 0): array;
}
