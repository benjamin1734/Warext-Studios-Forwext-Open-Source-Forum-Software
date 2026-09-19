<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final readonly class ReferralQualificationJobHandler
{
    public function __construct(private ReferralService $referrals)
    {
    }

    public function jobType(): string
    {
        return ReferralMaintenanceTasks::QUALIFY_JOB_TYPE;
    }

    public function handle(string $payload, DateTimeImmutable $now): int
    {
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Referral qualification payload is invalid JSON.', previous:$exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Referral qualification payload must be an object.');
        }
        $limit = $decoded['limit'] ?? 100;
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            throw new RuntimeException('Referral qualification limit is invalid.');
        }
        return $this->referrals->qualifyDue(null, $limit, $now);
    }
}
