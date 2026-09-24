<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Community;

enum AdminCommunitySection: string
{
    case Users = 'users';
    case Access = 'access';
    case Forums = 'forums';
    case Content = 'content';
    case Moderation = 'moderation';

    public function label(): string
    {
        return match ($this) {
            self::Users => 'Kullanıcılar',
            self::Access => 'Gruplar, Roller ve Yetkiler',
            self::Forums => 'Forumlar',
            self::Content => 'İçerik',
            self::Moderation => 'Moderasyon',
        };
    }

    public function path(): string
    {
        return '/admin/' . $this->value;
    }
}
