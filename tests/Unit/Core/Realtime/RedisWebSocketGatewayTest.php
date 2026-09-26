<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Realtime;

use DateTimeImmutable;
use Forwext\Core\Realtime\RealtimeEnvelope;
use Forwext\Core\Realtime\RealtimeMessage;
use Forwext\Core\Realtime\RedisWebSocketGateway;
use Forwext\Core\Redis\RedisScriptClient;
use PHPUnit\Framework\TestCase;

final class RedisWebSocketGatewayTest extends TestCase
{
    public function testBroadcastPublishesStableEnvelopeToSharedChannel(): void
    {
        $redis = $this->createMock(RedisScriptClient::class);
        $redis->expects(self::once())
            ->method('evaluate')
            ->with(
                self::stringContains("redis.call('PUBLISH'"),
                ['forwext:realtime:broadcast'],
                [self::callback(static function (string $payload): bool {
                    $decoded = json_decode($payload, true);

                    return is_array($decoded)
                        && ($decoded['sequence'] ?? null) === 42
                        && ($decoded['channel'] ?? null) === 'user:7'
                        && ($decoded['event'] ?? null) === 'notification.created'
                        && ($decoded['payload'] ?? null) === '{"id":9}';
                })],
            )
            ->willReturn(2);

        (new RedisWebSocketGateway($redis))->broadcast(new RealtimeEnvelope(
            42,
            new RealtimeMessage(
                'user:7',
                'notification.created',
                '{"id":9}',
                new DateTimeImmutable('2026-09-26T18:30:00+00:00'),
            ),
        ));
    }
}
