<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

enum ApiV1Scope: string
{
    case UsersRead = 'users.read';
    case ForumsRead = 'forums.read';
    case ThreadsRead = 'threads.read';
    case PostsRead = 'posts.read';
    case ConversationsRead = 'conversations.read';
    case NotificationsRead = 'notifications.read';
    case ModulesRead = 'modules.read';
    case MarketplaceRead = 'marketplace.read';
    case SupportRead = 'support.read';

    public function resource(): ApiV1Resource
    {
        return match ($this) {
            self::UsersRead => ApiV1Resource::Users,
            self::ForumsRead => ApiV1Resource::Forums,
            self::ThreadsRead => ApiV1Resource::Threads,
            self::PostsRead => ApiV1Resource::Posts,
            self::ConversationsRead => ApiV1Resource::Conversations,
            self::NotificationsRead => ApiV1Resource::Notifications,
            self::ModulesRead => ApiV1Resource::Modules,
            self::MarketplaceRead => ApiV1Resource::Marketplace,
            self::SupportRead => ApiV1Resource::Support,
        };
    }
}
