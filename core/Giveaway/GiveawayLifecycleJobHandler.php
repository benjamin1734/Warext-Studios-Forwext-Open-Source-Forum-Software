<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final readonly class GiveawayLifecycleJobHandler
{
    public function __construct(private GiveawayService $giveaways)
    {
    }

    public function jobType(): string
    {
        return GiveawayMaintenanceTasks::LIFECYCLE_JOB_TYPE;
    }

    public function handle(string $payload, DateTimeImmutable $now): int
    {
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Giveaway lifecycle payload is invalid JSON.', previous:$exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Giveaway lifecycle payload must be an object.');
        }
        $limit = $decoded['limit'] ?? 100;
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            throw new RuntimeException('Giveaway lifecycle limit is invalid.');
        }
        return $this->giveaways->syncDue($now, $limit);
    }
}
