<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class OversightReviewer
{
    public function __construct(
        public EntityId $userId,
        public string $username,
    ) {
        UserId::assert($this->userId);
        if (trim($this->username) === '' || strlen($this->username) > 128) {
            throw new InvalidArgumentException('Oversight reviewer username is invalid.');
        }
    }
}
