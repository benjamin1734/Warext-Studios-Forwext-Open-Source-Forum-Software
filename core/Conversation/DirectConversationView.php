<?php

declare(strict_types=1);

namespace Forwext\Core\Conversation;

final readonly class DirectConversationView
{
    /** @param list<DirectMessage> $messages */
    public function __construct(
        public DirectConversationSummary $summary,
        public array $messages,
    ) {
    }
}
