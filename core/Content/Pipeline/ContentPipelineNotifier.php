<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use DateTimeImmutable;

interface ContentPipelineNotifier
{
    public function notify(
        ContentPipelineContext $context,
        ContentPipelinePersisted $persisted,
        DateTimeImmutable $at,
    ): void;
}
