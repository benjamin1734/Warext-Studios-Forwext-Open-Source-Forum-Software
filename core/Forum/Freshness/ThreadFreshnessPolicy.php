<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Freshness;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeId;
use InvalidArgumentException;

final readonly class ThreadFreshnessPolicy
{
    public function __construct(
        public EntityId $forumNodeId,
        public bool $enabled,
        public int $staleAfterDays,
        public ?int $notifyAfterDays,
        public ?int $autoLockAfterDays,
        public ?int $autoArchiveAfterDays,
        public ?int $autoUnfeatureAfterDays,
        public ?int $moderatorReviewAfterDays,
        public int $renewalCooldownHours = 24,
    ) {
        ForumNodeId::assert($this->forumNodeId);
        if ($this->staleAfterDays < 1 || $this->staleAfterDays > 3650) {
            throw new InvalidArgumentException('Freshness stale window must be between 1 and 3650 days.');
        }
        foreach ([
            'notify' => $this->notifyAfterDays,
            'auto_lock' => $this->autoLockAfterDays,
            'auto_archive' => $this->autoArchiveAfterDays,
            'auto_unfeature' => $this->autoUnfeatureAfterDays,
            'moderator_review' => $this->moderatorReviewAfterDays,
        ] as $name => $days) {
            if ($days !== null && ($days < 1 || $days > 3650)) {
                throw new InvalidArgumentException('Freshness threshold is invalid: ' . $name);
            }
        }
        foreach ([
            $this->autoLockAfterDays,
            $this->autoArchiveAfterDays,
            $this->autoUnfeatureAfterDays,
            $this->moderatorReviewAfterDays,
        ] as $days) {
            if ($days !== null && $days < $this->staleAfterDays) {
                throw new InvalidArgumentException('Automatic freshness actions cannot run before the stale window.');
            }
        }
        if ($this->renewalCooldownHours < 0 || $this->renewalCooldownHours > 720) {
            throw new InvalidArgumentException('Freshness renewal cooldown must be between 0 and 720 hours.');
        }
    }
}
