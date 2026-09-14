<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

final readonly class RealtimeEnvelope
{
    public function __construct(
        public int $sequence,
        public RealtimeMessage $message,
    ) {
        if ($sequence < 1) {
            throw new RealtimeException('Realtime sequence must be positive.');
        }
    }
}
