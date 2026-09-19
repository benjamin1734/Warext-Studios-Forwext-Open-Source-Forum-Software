<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

interface AiModerationProvider
{
    public function key(): string;

    public function model(): string;

    public function assess(AiModerationRequest $request, int $timeoutMilliseconds): AiModerationAssessment;
}
