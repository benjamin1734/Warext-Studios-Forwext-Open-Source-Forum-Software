<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final class ProfilePostId
{
    public static function generate(): EntityId { return EntityId::fromString(bin2hex(random_bytes(16))); }
    public static function fromStored(string $value): EntityId { $id = EntityId::fromString($value); self::assert($id); return $id; }
    public static function assert(EntityId $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id->value()) !== 1) throw new InvalidArgumentException('Profile post id is invalid.');
    }
}
