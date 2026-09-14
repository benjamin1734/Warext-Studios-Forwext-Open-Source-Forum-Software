<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Event\AbstractDomainEvent;
use InvalidArgumentException;

final readonly class UserAccountEvent extends AbstractDomainEvent
{
    public function __construct(
        EntityId $userId,
        private string $name,
        DateTimeImmutable $occurredAt,
    ) {
        if (preg_match('/^user\.[a-z0-9._-]{1,120}$/D', $name) !== 1) {
            throw new InvalidArgumentException('User domain event name is invalid.');
        }
        parent::__construct($userId, $occurredAt);
    }

    public function eventName(): string
    {
        return $this->name;
    }
}
