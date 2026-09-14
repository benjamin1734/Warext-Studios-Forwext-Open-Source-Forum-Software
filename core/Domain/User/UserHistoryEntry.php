<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class UserHistoryEntry
{
    /** @var list<string> */
    public array $changedFields;

    /** @param list<string> $changedFields */
    public function __construct(
        public string $eventType,
        array $changedFields,
        public DateTimeImmutable $occurredAt,
        public ?EntityId $actorId = null,
        public ?UserStatus $fromStatus = null,
        public ?UserStatus $toStatus = null,
        public ?string $reasonCode = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $eventType) !== 1) {
            throw new InvalidArgumentException('User history event type is invalid.');
        }
        if ($changedFields === [] || count($changedFields) > 32) {
            throw new InvalidArgumentException('User history entry requires between 1 and 32 changed fields.');
        }
        $fields = [];
        foreach ($changedFields as $field) {
            if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $field) !== 1) {
                throw new InvalidArgumentException('User history changed-field name is invalid.');
            }
            $fields[$field] = true;
        }
        if (count($fields) !== count($changedFields)) {
            throw new InvalidArgumentException('User history changed fields must be unique.');
        }
        if (($fromStatus === null) !== ($toStatus === null)) {
            throw new InvalidArgumentException('User history status transition requires both from/to states.');
        }
        if ($reasonCode !== null && preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $reasonCode) !== 1) {
            throw new InvalidArgumentException('User history reason code is invalid.');
        }
        if ($actorId !== null) {
            UserId::assert($actorId);
        }
        $this->changedFields = array_keys($fields);
    }
}
