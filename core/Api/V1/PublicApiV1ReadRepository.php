<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

interface PublicApiV1ReadRepository
{
    /** @return array<string,mixed>|null */
    public function user(string $userId): ?array;

    public function forums(int $page, int $perPage): ApiV1Page;

    /** @return array<string,mixed>|null */
    public function forum(string $forumId): ?array;

    public function threads(string $forumId, int $page, int $perPage): ?ApiV1Page;

    /** @return array<string,mixed>|null */
    public function thread(string $threadId): ?array;

    public function posts(string $threadId, int $page, int $perPage): ?ApiV1Page;

    /** @return array<string,mixed>|null */
    public function post(string $postId): ?array;
}
