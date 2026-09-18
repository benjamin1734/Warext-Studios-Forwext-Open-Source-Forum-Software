<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

enum ContentPipelineStage: string
{
    case Validation = 'validation';
    case Spam = 'spam';
    case Spellcheck = 'spellcheck';
    case AiModeration = 'ai_moderation';
    case ModerationPolicy = 'moderation_policy';
    case Persist = 'persist';
    case Notify = 'notify';
    case Index = 'index';

    /** @return list<self> */
    public static function ordered(): array
    {
        return [
            self::Validation,
            self::Spam,
            self::Spellcheck,
            self::AiModeration,
            self::ModerationPolicy,
            self::Persist,
            self::Notify,
            self::Index,
        ];
    }

    /** @return list<self> */
    public static function prePersist(): array
    {
        return [
            self::Validation,
            self::Spam,
            self::Spellcheck,
            self::AiModeration,
            self::ModerationPolicy,
        ];
    }
}
