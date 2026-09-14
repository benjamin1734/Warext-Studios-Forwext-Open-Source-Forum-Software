<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

interface RealtimeMessageStore
{
    public function append(RealtimeMessage $message): RealtimeEnvelope;

    /** @return list<RealtimeEnvelope> */
    public function readAfter(string $channel, int $afterSequence, int $limit = 100): array;
}
