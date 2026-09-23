<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Dashboard;

use Forwext\Core\Admin\Navigation\AdminNavigationItem;
use Forwext\Core\Admin\Navigation\AdminNavigationSection;

final readonly class AdminDashboardSnapshot
{
    /**
     * @param array<string,list<AdminNavigationItem>> $sections
     * @param list<AdminNavigationItem> $searchResults
     * @param list<AdminNavigationItem> $favorites
     * @param list<AdminNavigationItem> $recent
     * @param list<AdminActionQueueItem> $actionQueues
     */
    public function __construct(
        public string $search,
        public array $sections,
        public array $searchResults,
        public array $favorites,
        public array $recent,
        public array $actionQueues,
    ) {
    }

    public function sectionLabel(string $section): string
    {
        return AdminNavigationSection::from($section)->label();
    }
}
