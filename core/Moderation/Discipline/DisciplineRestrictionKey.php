<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

enum DisciplineRestrictionKey: string
{
    case Posting = 'posting';
    case Content = 'content';

    public function label(): string
    {
        return match ($this) {
            self::Posting => 'Gönderi oluşturma',
            self::Content => 'İçerik oluşturma',
        };
    }
}
