<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

enum AbuseEventType: string
{
    case Registration = 'registration';
    case Thread = 'thread';
    case Post = 'post';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Kayıt',
            self::Thread => 'Konu',
            self::Post => 'Mesaj',
        };
    }
}
