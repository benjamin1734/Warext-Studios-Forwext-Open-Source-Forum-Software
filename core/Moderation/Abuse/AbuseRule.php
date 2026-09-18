<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use InvalidArgumentException;

final readonly class AbuseRule
{
    public function __construct(
        public string $key,
        public string $label,
        public AbuseEventType $eventType,
        public AbuseSignal $signal,
        public int $limit,
        public int $windowSeconds,
        public AbuseAction $action,
        public bool $active,
        public int $priority = 100,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Abuse rule key is invalid.');
        }
        if (trim($this->label) === '' || strlen($this->label) > 120) {
            throw new InvalidArgumentException('Abuse rule label must contain 1-120 bytes.');
        }
        if ($this->limit < 1 || $this->limit > 100000) {
            throw new InvalidArgumentException('Abuse rule limit is outside the supported range.');
        }
        if ($this->windowSeconds < 1 || $this->windowSeconds > 604800) {
            throw new InvalidArgumentException('Abuse rule window must be between 1 second and 7 days.');
        }
        if ($this->action === AbuseAction::Allow) {
            throw new InvalidArgumentException('Persisted abuse rules must take review or reject action.');
        }
        if ($this->priority < 0 || $this->priority > 65535) {
            throw new InvalidArgumentException('Abuse rule priority is outside the supported range.');
        }
    }
}
