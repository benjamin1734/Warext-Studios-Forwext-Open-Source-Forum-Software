<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

final readonly class PollingRealtimeTransport implements RealtimeTransport
{
    public function __construct(private RealtimeMessageStore $store)
    {
    }

    public function mode(): RealtimeMode
    {
        return RealtimeMode::Polling;
    }

    public function publish(RealtimeMessage $message): RealtimeEnvelope
    {
        return $this->store->append($message);
    }

    public function readAfter(string $channel, int $afterSequence, int $limit = 100): array
    {
        return $this->store->readAfter($channel, $afterSequence, $limit);
    }
}
