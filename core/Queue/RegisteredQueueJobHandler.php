<?php

declare(strict_types=1);

namespace Forwext\Core\Queue;

use Forwext\Core\Extension\ExtensionOwner;

final readonly class RegisteredQueueJobHandler
{
    public function __construct(
        public ExtensionOwner $owner,
        public QueueJobHandler $handler,
    ) {
    }
}
