<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\Core\Bug\Report\BugReportCategory;
use Forwext\Core\Bug\Staff\BugDuplicateLink;
use Forwext\Core\Bug\Staff\BugDuplicateSuggestion;

final readonly class BugReportStaffContext
{
    /**
     * @param list<BugReportCategory> $categories
     * @param list<BugDuplicateSuggestion> $duplicateSuggestions
     */
    public function __construct(
        public array $categories,
        public array $duplicateSuggestions,
        public ?BugDuplicateLink $duplicateLink,
        public ?string $assigneeUsername,
    ) {
    }
}
