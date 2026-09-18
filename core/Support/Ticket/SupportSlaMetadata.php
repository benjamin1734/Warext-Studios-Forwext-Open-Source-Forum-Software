<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Ticket;

use DateTimeImmutable;
use DateTimeZone;

final readonly class SupportSlaMetadata
{
    public ?DateTimeImmutable $firstResponseDueAt;
    public ?DateTimeImmutable $resolutionDueAt;
    public ?DateTimeImmutable $firstRespondedAt;
    public ?DateTimeImmutable $resolvedAt;

    public function __construct(
        ?DateTimeImmutable $firstResponseDueAt,
        ?DateTimeImmutable $resolutionDueAt,
        ?DateTimeImmutable $firstRespondedAt = null,
        ?DateTimeImmutable $resolvedAt = null,
    ) {
        $utc = new DateTimeZone('UTC');
        $this->firstResponseDueAt = $firstResponseDueAt?->setTimezone($utc);
        $this->resolutionDueAt = $resolutionDueAt?->setTimezone($utc);
        $this->firstRespondedAt = $firstRespondedAt?->setTimezone($utc);
        $this->resolvedAt = $resolvedAt?->setTimezone($utc);
    }

    public function firstResponseBreached(DateTimeImmutable $at): bool
    {
        if ($this->firstResponseDueAt === null) {
            return false;
        }
        $actual = $this->firstRespondedAt ?? $at;
        return $actual > $this->firstResponseDueAt;
    }

    public function resolutionBreached(DateTimeImmutable $at): bool
    {
        if ($this->resolutionDueAt === null) {
            return false;
        }
        $actual = $this->resolvedAt ?? $at;
        return $actual > $this->resolutionDueAt;
    }

    public function withFirstResponse(DateTimeImmutable $at): self
    {
        return $this->firstRespondedAt === null
            ? new self($this->firstResponseDueAt, $this->resolutionDueAt, $at, $this->resolvedAt)
            : $this;
    }

    public function withResolved(?DateTimeImmutable $at): self
    {
        return new self($this->firstResponseDueAt, $this->resolutionDueAt, $this->firstRespondedAt, $at);
    }
}
