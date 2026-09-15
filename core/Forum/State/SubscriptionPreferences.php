<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\State;

final readonly class SubscriptionPreferences
{
    public function __construct(
        private bool $autoWatchCreatedThreads = true,
        private bool $autoWatchRepliedThreads = false,
        private WatchNotificationMode $defaultThreadMode = WatchNotificationMode::InApp,
        private WatchNotificationMode $defaultForumMode = WatchNotificationMode::InApp,
    ) {
    }

    public function autoWatchCreatedThreads(): bool { return $this->autoWatchCreatedThreads; }
    public function autoWatchRepliedThreads(): bool { return $this->autoWatchRepliedThreads; }
    public function defaultThreadMode(): WatchNotificationMode { return $this->defaultThreadMode; }
    public function defaultForumMode(): WatchNotificationMode { return $this->defaultForumMode; }
}
