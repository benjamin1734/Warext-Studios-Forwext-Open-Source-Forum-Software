<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use InvalidArgumentException;

final readonly class AbuseDecision
{
    /** @param list<string> $matchedKeys */
    public function __construct(
        public AbuseAction $action,
        public array $matchedKeys = [],
    ) {
        $seen = [];
        foreach ($this->matchedKeys as $key) {
            if (!is_string($key) || preg_match('/^(?:[a-z][a-z0-9._-]{1,63}|control:[a-f0-9]{32})$/D', $key) !== 1) {
                throw new InvalidArgumentException('Abuse decision matched key is invalid.');
            }
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Abuse decision matched keys must be unique.');
            }
            $seen[$key] = true;
        }
        if ($this->action === AbuseAction::Allow && $this->matchedKeys !== []) {
            throw new InvalidArgumentException('Allow decisions cannot contain matched blocking keys.');
        }
    }

    public static function allow(): self
    {
        return new self(AbuseAction::Allow);
    }

    public function requiresReview(): bool
    {
        return $this->action === AbuseAction::Review;
    }

    public function isRejected(): bool
    {
        return $this->action === AbuseAction::Reject;
    }
}
