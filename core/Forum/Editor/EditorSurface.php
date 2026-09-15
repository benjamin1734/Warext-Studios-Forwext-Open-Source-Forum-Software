<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

enum EditorSurface: string
{
    case Thread = 'thread';
    case Post = 'post';

    public function label(): string
    {
        return match ($this) {
            self::Thread => 'Konu içeriği',
            self::Post => 'Mesaj içeriği',
        };
    }
}
