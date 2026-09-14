<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Realtime;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Realtime\PollingRealtimeTransport;
use Forwext\Core\Realtime\RealtimeEnvelope;
use Forwext\Core\Realtime\RealtimeMessage;
use Forwext\Core\Realtime\RealtimeMessageStore;
use Forwext\Core\Realtime\RealtimeMode;
use Forwext\Core\Realtime\SseRealtimeTransport;
use Forwext\Core\Realtime\WebSocketGateway;
use Forwext\Core\Realtime\WebSocketRealtimeTransport;
use PHPUnit\Framework\TestCase;

final class RealtimeTransportTest extends TestCase
{
    public function testPollingUsesMonotonicCursor(): void
    {
        $store = new MemoryRealtimeMessageStore();
        $transport = new PollingRealtimeTransport($store);
        $first = $transport->publish($this->message('chat:1', 'message.created', 'one'));
        $second = $transport->publish($this->message('chat:1', 'message.created', 'two'));

        self::assertSame(RealtimeMode::Polling, $transport->mode());
        self::assertSame(1, $first->sequence);
        self::assertSame(2, $second->sequence);
        self::assertSame('two', $transport->readAfter('chat:1', 1)[0]->message->payload);
    }

    public function testSseEncodingIsBinarySafeAndResumable(): void
    {
        $store = new MemoryRealtimeMessageStore();
        $transport = new SseRealtimeTransport($store);
        $envelope = $transport->publish($this->message('alerts', 'alert.created', "\x00\xFFbinary"));
        $encoded = $transport->encode([$envelope]);

        self::assertStringContainsString("id: 1\n", $encoded);
        self::assertStringContainsString("event: alert.created\n", $encoded);
        self::assertStringContainsString('data: ' . base64_encode("\x00\xFFbinary"), $encoded);
    }

    public function testWebSocketTransportPersistsBeforeBroadcast(): void
    {
        $store = new MemoryRealtimeMessageStore();
        $gateway = new RecordingWebSocketGateway();
        $transport = new WebSocketRealtimeTransport($store, $gateway);

        $envelope = $transport->publish($this->message('presence', 'presence.changed', 'online'));

        self::assertSame(RealtimeMode::WebSocket, $transport->mode());
        self::assertSame($envelope, $gateway->broadcasts[0]);
        self::assertSame($envelope, $transport->readAfter('presence', 0)[0]);
    }

    private function message(string $channel, string $event, string $payload): RealtimeMessage
    {
        return new RealtimeMessage(
            $channel,
            $event,
            $payload,
            new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC')),
        );
    }
}

final class MemoryRealtimeMessageStore implements RealtimeMessageStore
{
    /** @var list<RealtimeEnvelope> */
    private array $messages = [];

    public function append(RealtimeMessage $message): RealtimeEnvelope
    {
        $envelope = new RealtimeEnvelope(count($this->messages) + 1, $message);
        $this->messages[] = $envelope;
        return $envelope;
    }

    public function readAfter(string $channel, int $afterSequence, int $limit = 100): array
    {
        $result = [];
        foreach ($this->messages as $envelope) {
            if ($envelope->sequence > $afterSequence && $envelope->message->channel === $channel) {
                $result[] = $envelope;
                if (count($result) >= $limit) {
                    break;
                }
            }
        }
        return $result;
    }
}

final class RecordingWebSocketGateway implements WebSocketGateway
{
    /** @var list<RealtimeEnvelope> */
    public array $broadcasts = [];

    public function broadcast(RealtimeEnvelope $message): void
    {
        $this->broadcasts[] = $message;
    }
}
