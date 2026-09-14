<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

interface RealtimeTransport
{
    public function mode(): RealtimeMode;

    public function publish(RealtimeMessage $message): RealtimeEnvelope;

    /** @return list<RealtimeEnvelope> */
    public function readAfter(string $channel, int $afterSequence, int $limit = 100): array;
}
