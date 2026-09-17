<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Search;

use DateTimeImmutable;
use Forwext\Core\Search\AdvancedSearchFilters;
use Forwext\Core\Search\Saved\SavedSearchQueryExtension;
use Forwext\Core\Search\Saved\SavedSearchQueryRegistry;
use Forwext\Core\Search\Saved\SavedSearchQuery;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AdvancedSearchFilterTest extends TestCase
{
    public function testAdvancedFiltersExposeOnlyValidatedAttributeCategories(): void
    {
        $filters = new AdvancedSearchFilters(
            forumIds: ['forum-1'], userIds: ['user-1'], prefixIds: ['prefix-1'], tagIds: ['tag-1'],
            states: ['visible'], threadTypes: ['discussion'],
            updatedAfter: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            updatedBefore: new DateTimeImmutable('2026-09-30T23:59:59+00:00'),
        );
        self::assertSame([
            'forum' => ['forum-1'], 'user' => ['user-1'], 'prefix' => ['prefix-1'],
            'tag' => ['tag-1'], 'state' => ['visible'], 'thread_type' => ['discussion'],
        ], $filters->attributes());
    }

    public function testDateRangeCannotBeReversed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AdvancedSearchFilters(
            updatedAfter: new DateTimeImmutable('2026-10-01T00:00:00+00:00'),
            updatedBefore: new DateTimeImmutable('2026-09-01T00:00:00+00:00'),
        );
    }

    public function testSavedQueryRegistryRejectsDuplicateKeys(): void
    {
        $extension = new AdvancedSearchFakeExtension();
        $registry = new SavedSearchQueryRegistry([$extension]);
        $this->expectException(InvalidArgumentException::class);
        $registry->register($extension);
    }
}

final class AdvancedSearchFakeExtension implements SavedSearchQueryExtension
{
    public function key(): string { return 'my.saved-query'; }
    public function query(EntityId $userId): SavedSearchQuery { return new SavedSearchQuery(['thread']); }
}
