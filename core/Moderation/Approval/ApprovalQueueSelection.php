<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Approval;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ApprovalQueueSelection
{
    public function __construct(
        public string $sourceType,
        public EntityId $sourceId,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,47}$/D', $this->sourceType) !== 1) {
            throw new InvalidArgumentException('Approval queue source type is invalid.');
        }
    }

    public static function fromToken(string $token): self
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 256) {
            throw new InvalidArgumentException('Approval queue selection token is invalid.');
        }
        $separator = strpos($token, ':');
        if ($separator === false || $separator < 2 || $separator === strlen($token) - 1) {
            throw new InvalidArgumentException('Approval queue selection token is invalid.');
        }

        return new self(
            substr($token, 0, $separator),
            EntityId::fromString(substr($token, $separator + 1)),
        );
    }

    public function token(): string
    {
        return $this->sourceType . ':' . $this->sourceId->value();
    }
}
