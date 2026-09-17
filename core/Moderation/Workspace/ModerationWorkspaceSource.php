<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Workspace;

interface ModerationWorkspaceSource
{
    public function section(): ModerationWorkspaceSection;

    public function count(): int;

    /** @return list<ModerationWorkspaceItem> */
    public function latest(int $limit): array;
}
