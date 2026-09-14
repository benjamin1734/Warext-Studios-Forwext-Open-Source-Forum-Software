<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RealtimeMessage
{
    public function __construct(
        public string $channel,
        public string $event,
        public string $payload,
        public DateTimeImmutable $createdAt,
    ) {
        foreach ([$channel, $event] as $value) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $value) !== 1) {
                throw new InvalidArgumentException('Realtime channel/event is invalid.');
            }
        }
    }
}
