<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

final readonly class SseRealtimeTransport implements RealtimeTransport
{
    public function __construct(private RealtimeMessageStore $store)
    {
    }

    public function mode(): RealtimeMode
    {
        return RealtimeMode::Sse;
    }

    public function publish(RealtimeMessage $message): RealtimeEnvelope
    {
        return $this->store->append($message);
    }

    public function readAfter(string $channel, int $afterSequence, int $limit = 100): array
    {
        return $this->store->readAfter($channel, $afterSequence, $limit);
    }

    /** @param list<RealtimeEnvelope> $messages */
    public function encode(array $messages): string
    {
        $output = '';
        foreach ($messages as $envelope) {
            $output .= 'id: ' . $envelope->sequence . "\n";
            $output .= 'event: ' . $envelope->message->event . "\n";
            $output .= 'data: ' . base64_encode($envelope->message->payload) . "\n\n";
        }

        return $output;
    }
}
