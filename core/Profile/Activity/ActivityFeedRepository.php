<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

interface ActivityFeedRepository
{
    /** @return list<ActivityFeedEntry> */
    public function candidates(int $limit = 100, int $offset = 0): array;
}
