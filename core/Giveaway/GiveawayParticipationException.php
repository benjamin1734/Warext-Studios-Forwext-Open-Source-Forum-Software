<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use RuntimeException;

final class GiveawayParticipationException extends RuntimeException
{
    /** @param list<string> $reasonCodes */
    public function __construct(
        string $message,
        public readonly array $reasonCodes = [],
    ) {
        parent::__construct($message);
    }
}
