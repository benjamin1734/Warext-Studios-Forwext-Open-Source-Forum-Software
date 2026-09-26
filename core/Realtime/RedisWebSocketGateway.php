<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

use Forwext\Core\Redis\RedisScriptClient;
use JsonException;

final readonly class RedisWebSocketGateway implements WebSocketGateway
{
    public function __construct(
        private RedisScriptClient $redis,
        private string $channel = 'forwext:realtime:broadcast',
    ) {
        if ($channel === '' || strlen($channel) > 191 || str_contains($channel, "\0")) {
            throw new RealtimeException('Redis realtime broadcast channel is invalid.');
        }
    }

    public function broadcast(RealtimeEnvelope $message): void
    {
        try {
            $payload = json_encode([
                'sequence' => $message->sequence,
                'channel' => $message->message->channel,
                'event' => $message->message->event,
                'payload' => $message->message->payload,
                'created_at' => $message->message->createdAt->format(DATE_ATOM),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RealtimeException('Unable to encode realtime broadcast payload.', previous: $exception);
        }

        $result = $this->redis->evaluate(
            "return redis.call('PUBLISH', KEYS[1], ARGV[1])",
            [$this->channel],
            [$payload],
        );

        if (!is_int($result) && !(is_string($result) && ctype_digit($result))) {
            throw new RealtimeException('Redis returned an invalid realtime publish result.');
        }
    }
}
