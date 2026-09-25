<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

use Forwext\Core\Http\HttpMethod;
use InvalidArgumentException;

final class ApiV1EndpointRegistry
{
    /** @var array<string,ApiV1EndpointDefinition> */
    private array $endpoints = [];

    /** @param iterable<ApiV1EndpointDefinition> $endpoints */
    public function __construct(iterable $endpoints = [])
    {
        foreach ($endpoints as $endpoint) {
            $this->register($endpoint);
        }
    }

    public static function coreDefaults(): self
    {
        $id = '[a-f0-9]{32}';

        return new self([
            new ApiV1EndpointDefinition('api.v1.root','/api/v1',[HttpMethod::Get],ApiV1Operation::ServiceDocument),
            new ApiV1EndpointDefinition('api.v1.users.show','/api/v1/users/{userId}',[HttpMethod::Get],ApiV1Operation::UserShow,ApiV1Resource::Users,ApiV1Scope::UsersRead,true,['userId'=>$id]),
            new ApiV1EndpointDefinition('api.v1.forums.index','/api/v1/forums',[HttpMethod::Get],ApiV1Operation::ForumIndex,ApiV1Resource::Forums,ApiV1Scope::ForumsRead),
            new ApiV1EndpointDefinition('api.v1.forums.show','/api/v1/forums/{forumId}',[HttpMethod::Get],ApiV1Operation::ForumShow,ApiV1Resource::Forums,ApiV1Scope::ForumsRead,true,['forumId'=>$id]),
            new ApiV1EndpointDefinition('api.v1.forums.threads','/api/v1/forums/{forumId}/threads',[HttpMethod::Get],ApiV1Operation::ForumThreads,ApiV1Resource::Threads,ApiV1Scope::ThreadsRead,true,['forumId'=>$id]),
            new ApiV1EndpointDefinition('api.v1.threads.show','/api/v1/threads/{threadId}',[HttpMethod::Get],ApiV1Operation::ThreadShow,ApiV1Resource::Threads,ApiV1Scope::ThreadsRead,true,['threadId'=>$id]),
            new ApiV1EndpointDefinition('api.v1.threads.posts','/api/v1/threads/{threadId}/posts',[HttpMethod::Get],ApiV1Operation::ThreadPosts,ApiV1Resource::Posts,ApiV1Scope::PostsRead,true,['threadId'=>$id]),
            new ApiV1EndpointDefinition('api.v1.posts.show','/api/v1/posts/{postId}',[HttpMethod::Get],ApiV1Operation::PostShow,ApiV1Resource::Posts,ApiV1Scope::PostsRead,true,['postId'=>$id]),
            new ApiV1EndpointDefinition('api.v1.conversations.index','/api/v1/conversations',[HttpMethod::Get],ApiV1Operation::ConversationIndex,ApiV1Resource::Conversations,ApiV1Scope::ConversationsRead,false),
            new ApiV1EndpointDefinition('api.v1.notifications.index','/api/v1/notifications',[HttpMethod::Get],ApiV1Operation::NotificationIndex,ApiV1Resource::Notifications,ApiV1Scope::NotificationsRead,false),
            new ApiV1EndpointDefinition('api.v1.modules.index','/api/v1/modules',[HttpMethod::Get],ApiV1Operation::ModuleIndex,ApiV1Resource::Modules,ApiV1Scope::ModulesRead),
            new ApiV1EndpointDefinition('api.v1.marketplace.index','/api/v1/marketplace',[HttpMethod::Get],ApiV1Operation::MarketplaceIndex,ApiV1Resource::Marketplace,ApiV1Scope::MarketplaceRead),
            new ApiV1EndpointDefinition('api.v1.marketplace.show','/api/v1/marketplace/{listingId}',[HttpMethod::Get],ApiV1Operation::MarketplaceShow,ApiV1Resource::Marketplace,ApiV1Scope::MarketplaceRead,true,['listingId'=>$id]),
            new ApiV1EndpointDefinition('api.v1.support.categories','/api/v1/support/categories',[HttpMethod::Get],ApiV1Operation::SupportCategoryIndex,ApiV1Resource::Support,ApiV1Scope::SupportRead),
            new ApiV1EndpointDefinition('api.v1.support.tickets','/api/v1/support/tickets',[HttpMethod::Get],ApiV1Operation::SupportTicketIndex,ApiV1Resource::Support,ApiV1Scope::SupportRead,false),
        ]);
    }

    public function register(ApiV1EndpointDefinition $endpoint): void
    {
        if (isset($this->endpoints[$endpoint->routeName])) {
            throw new InvalidArgumentException('API v1 route is already registered: ' . $endpoint->routeName);
        }
        $this->endpoints[$endpoint->routeName] = $endpoint;
    }

    /** @return list<ApiV1EndpointDefinition> */
    public function all(): array
    {
        return array_values($this->endpoints);
    }
}
