<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

interface OversightReviewerDirectory
{
    /** @return list<OversightReviewer> */
    public function list(int $limit = 50): array;
}
