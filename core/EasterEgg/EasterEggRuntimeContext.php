<?php

declare(strict_types=1);

namespace Forwext\Core\EasterEgg;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class EasterEggRuntimeContext
{
    /** @var list<EntityId> */
    public array $groupIds;

    /** @param list<EntityId> $groupIds */
    public function __construct(
        public string $routeName,
        public string $path,
        public ?string $queryToken,
        array $groupIds,
        public DateTimeImmutable $at,
    ) {
        if ($routeName === '' || $path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('Easter egg runtime route context is invalid.');
        }
        $groups = [];
        foreach ($groupIds as $groupId) {
            if (!$groupId instanceof EntityId) {
                throw new InvalidArgumentException('Easter egg runtime groups are invalid.');
            }
            $groups[$groupId->value()] = $groupId;
        }
        $this->groupIds = array_values($groups);
    }
}
