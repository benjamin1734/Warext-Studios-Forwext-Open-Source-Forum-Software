<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AdvancedSearchFilters
{
    /** @var list<string> */ public array $forumIds;
    /** @var list<string> */ public array $userIds;
    /** @var list<string> */ public array $prefixIds;
    /** @var list<string> */ public array $tagIds;
    /** @var list<string> */ public array $states;
    /** @var list<string> */ public array $threadTypes;

    /**
     * @param list<string> $forumIds
     * @param list<string> $userIds
     * @param list<string> $prefixIds
     * @param list<string> $tagIds
     * @param list<string> $states
     * @param list<string> $threadTypes
     */
    public function __construct(
        array $forumIds = [],
        array $userIds = [],
        array $prefixIds = [],
        array $tagIds = [],
        array $states = [],
        array $threadTypes = [],
        public ?DateTimeImmutable $updatedAfter = null,
        public ?DateTimeImmutable $updatedBefore = null,
    ) {
        $this->forumIds = self::normalize($forumIds, SearchAttribute::FORUM);
        $this->userIds = self::normalize($userIds, SearchAttribute::USER);
        $this->prefixIds = self::normalize($prefixIds, SearchAttribute::PREFIX);
        $this->tagIds = self::normalize($tagIds, SearchAttribute::TAG);
        $this->states = self::normalize($states, SearchAttribute::STATE);
        $this->threadTypes = self::normalize($threadTypes, SearchAttribute::THREAD_TYPE);

        if ($updatedAfter !== null && $updatedBefore !== null && $updatedAfter > $updatedBefore) {
            throw new InvalidArgumentException('Search updated-after bound cannot be later than updated-before bound.');
        }
    }

    public function isEmpty(): bool
    {
        return $this->forumIds === []
            && $this->userIds === []
            && $this->prefixIds === []
            && $this->tagIds === []
            && $this->states === []
            && $this->threadTypes === []
            && $this->updatedAfter === null
            && $this->updatedBefore === null;
    }

    /** @return array<string, list<string>> */
    public function attributes(): array
    {
        $attributes = [];
        foreach ([
            SearchAttribute::FORUM => $this->forumIds,
            SearchAttribute::USER => $this->userIds,
            SearchAttribute::PREFIX => $this->prefixIds,
            SearchAttribute::TAG => $this->tagIds,
            SearchAttribute::STATE => $this->states,
            SearchAttribute::THREAD_TYPE => $this->threadTypes,
        ] as $key => $values) {
            if ($values !== []) $attributes[$key] = $values;
        }
        return $attributes;
    }

    /** @param list<string> $values @return list<string> */
    private static function normalize(array $values, string $key): array
    {
        if (count($values) > 32) {
            throw new InvalidArgumentException(sprintf('Search %s filter accepts at most 32 values.', $key));
        }
        $map = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf('Search %s filter values must be strings.', $key));
            }
            SearchAttribute::validateValue($value);
            $map[$value] = true;
        }
        if (count($map) !== count($values)) {
            throw new InvalidArgumentException(sprintf('Search %s filter values must be unique.', $key));
        }
        return array_keys($map);
    }
}
