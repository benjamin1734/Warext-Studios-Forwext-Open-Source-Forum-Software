<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

enum AbuseSignal: string
{
    case User = 'user';
    case Identity = 'identity';
    case Ip = 'ip';
    case Device = 'device';
    case Content = 'content';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Kullanıcı',
            self::Identity => 'Kimlik',
            self::Ip => 'IP fingerprint',
            self::Device => 'Cihaz fingerprint',
            self::Content => 'İçerik fingerprint',
        };
    }
}
