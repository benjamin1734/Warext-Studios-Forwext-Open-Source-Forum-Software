<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

final readonly class AbuseOverview
{
    /**
     * @param list<AbuseRule> $rules
     * @param list<AbuseEvent> $events
     */
    public function __construct(
        public array $rules,
        public array $events,
    ) {
    }
}
