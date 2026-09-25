<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

use InvalidArgumentException;

final readonly class ApiV1Page
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public bool $hasMore,
    ) {
        if ($this->page < 1 || $this->perPage < 1 || $this->perPage > 100) {
            throw new InvalidArgumentException('API v1 page metadata is invalid.');
        }
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array{page:int,per_page:int,has_more:bool}} */
    public function toArray(): array
    {
        return [
            'items'=>$this->items,
            'pagination'=>[
                'page'=>$this->page,
                'per_page'=>$this->perPage,
                'has_more'=>$this->hasMore,
            ],
        ];
    }
}
