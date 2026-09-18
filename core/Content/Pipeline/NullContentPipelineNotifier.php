<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Pipeline;

use DateTimeImmutable;

final readonly class NullContentPipelineNotifier implements ContentPipelineNotifier
{
    public function notify(
        ContentPipelineContext $context,
        ContentPipelinePersisted $persisted,
        DateTimeImmutable $at,
    ): void {
    }
}
