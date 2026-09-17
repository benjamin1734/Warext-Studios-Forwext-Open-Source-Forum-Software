<?php

declare(strict_types=1);

namespace Forwext\Core\Presence;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;

final readonly class PresenceService
{
    private const ONLINE_WINDOW_SECONDS = 300;

    public function __construct(
        private DatabasePresenceRepository $repository,
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function heartbeat(EntityId $userId): void
    {
        $this->repository->touch($userId, $this->clock->now());
    }

    public function setVisibility(EntityId $userId, PresenceVisibility $visibility): void
    {
        $this->repository->setVisibility($userId, $visibility, $this->clock->now());
    }

    public function visibility(EntityId $userId): PresenceVisibility
    {
        return $this->repository->visibility($userId);
    }

    /** @return list<OnlineUser> */
    public function online(bool $viewerAuthenticated, int $limit = 50): array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        return $this->repository->online(
            $viewerAuthenticated,
            $now->sub(new DateInterval('PT' . self::ONLINE_WINDOW_SECONDS . 'S')),
            $limit,
        );
    }
}
