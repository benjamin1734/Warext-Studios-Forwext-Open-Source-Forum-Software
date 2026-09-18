<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\Core\Bug\Staff\BugStaffFilter;

final readonly class BugStaffFilterRequest
{
    public function __construct(
        public BugStaffFilter $filter,
        public ?string $assigneeQuery,
    ) {
    }
}
