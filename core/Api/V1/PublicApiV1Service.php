<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

final readonly class PublicApiV1Service
{
    public function __construct(
        private PublicApiV1ReadRepository $reads,
        private ApiV1EndpointRegistry $endpoints,
    ) {
    }

    /** @return array<string,mixed> */
    public function serviceDocument(): array
    {
        return [
            'version'=>'v1',
            'resources'=>array_map(static fn (ApiV1Resource $resource): string => $resource->value, ApiV1Resource::cases()),
            'scopes'=>array_map(static fn (ApiV1Scope $scope): string => $scope->value, ApiV1Scope::cases()),
            'endpoints'=>array_map(
                static fn (ApiV1EndpointDefinition $endpoint): array => [
                    'name'=>$endpoint->routeName,
                    'methods'=>array_map(static fn ($method): string => $method->value, $endpoint->methods),
                    'path'=>$endpoint->path,
                    'resource'=>$endpoint->resource?->value,
                    'scope'=>$endpoint->scope?->value,
                    'public'=>$endpoint->public,
                ],
                $this->endpoints->all(),
            ),
        ];
    }

    public function user(string $id): ?array { return $this->reads->user($id); }
    public function forums(int $page, int $perPage): ApiV1Page { return $this->reads->forums($page, $perPage); }
    public function forum(string $id): ?array { return $this->reads->forum($id); }
    public function threads(string $forumId, int $page, int $perPage): ?ApiV1Page { return $this->reads->threads($forumId, $page, $perPage); }
    public function thread(string $id): ?array { return $this->reads->thread($id); }
    public function posts(string $threadId, int $page, int $perPage): ?ApiV1Page { return $this->reads->posts($threadId, $page, $perPage); }
    public function post(string $id): ?array { return $this->reads->post($id); }
    public function modules(int $page, int $perPage): ApiV1Page { return $this->reads->modules($page, $perPage); }
    public function marketplace(int $page, int $perPage): ApiV1Page { return $this->reads->marketplace($page, $perPage); }
    public function marketplaceListing(string $id): ?array { return $this->reads->marketplaceListing($id); }
    public function supportCategories(int $page, int $perPage): ApiV1Page { return $this->reads->supportCategories($page, $perPage); }
}
