<?php

declare(strict_types=1);

namespace Forwext\Core\Advertising;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AdvertisingRuntimeContext
{
    public DateTimeImmutable $now;

    /** @param list<EntityId> $groupIds */
    public function __construct(
        public string $routeName,
        public ?EntityId $forumId,
        public array $groupIds,
        public AdvertisingDevice $device,
        public string $viewerHash,
        DateTimeImmutable $now,
    ) {
        if ($this->routeName === '' || strlen($this->routeName) > 128) {
            throw new InvalidArgumentException('Advertising route name is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->viewerHash) !== 1) {
            throw new InvalidArgumentException('Advertising viewer hash is invalid.');
        }
        foreach ($this->groupIds as $groupId) {
            if (!$groupId instanceof EntityId) {
                throw new InvalidArgumentException('Advertising group context is invalid.');
            }
        }
        $this->now = $now->setTimezone(new DateTimeZone('UTC'));
    }
}
