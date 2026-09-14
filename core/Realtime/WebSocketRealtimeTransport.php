<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

final readonly class WebSocketRealtimeTransport implements RealtimeTransport
{
    public function __construct(
        private RealtimeMessageStore $store,
        private WebSocketGateway $gateway,
    ) {
    }

    public function mode(): RealtimeMode
    {
        return RealtimeMode::WebSocket;
    }

    public function publish(RealtimeMessage $message): RealtimeEnvelope
    {
        $envelope = $this->store->append($message);
        $this->gateway->broadcast($envelope);
        return $envelope;
    }

    public function readAfter(string $channel, int $afterSequence, int $limit = 100): array
    {
        return $this->store->readAfter($channel, $afterSequence, $limit);
    }
}
