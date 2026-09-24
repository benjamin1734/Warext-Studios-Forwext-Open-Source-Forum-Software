<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class FirstPartyModuleRecord
{
    public function __construct(
        public string $moduleKey,
        public FirstPartyModuleState $state,
        public FirstPartyModuleDataState $dataState,
        public ?EntityId $updatedByUserId,
        public ?DateTimeImmutable $updatedAt,
    ) {
    }

    public static function defaultEnabled(string $moduleKey): self
    {
        return new self(
            $moduleKey,
            FirstPartyModuleState::Enabled,
            FirstPartyModuleDataState::Retained,
            null,
            null,
        );
    }
}
