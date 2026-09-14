<?php

declare(strict_types=1);

namespace Forwext\Core\Realtime;

enum RealtimeMode: string
{
    case Polling = 'polling';
    case Sse = 'sse';
    case WebSocket = 'websocket';
}
