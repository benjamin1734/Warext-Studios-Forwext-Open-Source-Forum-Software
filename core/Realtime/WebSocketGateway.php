<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

interface WebSocketGateway
{
    public function broadcast(RealtimeEnvelope $message): void;
}
