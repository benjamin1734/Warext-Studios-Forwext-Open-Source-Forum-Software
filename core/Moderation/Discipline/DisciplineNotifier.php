<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

interface DisciplineNotifier
{
    public function issued(DisciplineAction $action): void;
    public function revoked(DisciplineAction $action): void;
}
